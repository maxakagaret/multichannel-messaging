<?php

namespace App\Messaging\Stats;

use App\Enums\Channel;
use App\Enums\MessageStatus;
use App\Models\Campaign;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Delivery funnel per channel: counts by status are aggregated in SQL; latency
 * (sent → delivered) is computed from timestamps in PHP to stay portable across
 * MySQL and SQLite.
 *
 * Rates use "attempted" (reached the provider) as the denominator, so messages
 * still waiting in the queue do not drag the delivery rate down mid-campaign.
 */
class DeliveryStats
{
    public function forCampaign(Campaign $campaign): array
    {
        $base = Message::where('campaign_id', $campaign->id);

        return [
            'campaign_id' => $campaign->id,
            'planned_recipients' => $campaign->recipients()->count(),
            'processed_recipients' => $campaign->recipients()->whereNotNull('processed_at')->count(),
            'channels' => $this->byChannel(clone $base),
            'fallbacks' => (clone $base)->whereNotNull('fallback_of_id')->count(),
            'top_errors' => $this->topErrors(clone $base),
        ];
    }

    public function overview(CarbonImmutable $since): array
    {
        $base = Message::where('created_at', '>=', $since);

        return [
            'since' => $since->toIso8601String(),
            'channels' => $this->byChannel(clone $base),
            'top_errors' => $this->topErrors(clone $base),
            'hourly' => $this->hourly(clone $base, $since),
        ];
    }

    private function byChannel(Builder $query): array
    {
        // toBase() hands out the shared query object, so clone before adding the grouping.
        $counts = (clone $query)->toBase()
            ->select('channel', 'status', DB::raw('count(*) as total'))
            ->groupBy('channel', 'status')
            ->get()
            ->groupBy('channel');

        $latency = $this->latencyByChannel(clone $query);

        return collect(Channel::cases())->map(function (Channel $channel) use ($counts, $latency) {
            $byStatus = collect(MessageStatus::cases())->mapWithKeys(fn (MessageStatus $s) => [
                $s->value => (int) ($counts->get($channel->value)?->firstWhere('status', $s->value)?->total ?? 0),
            ]);

            $delivered = $byStatus['delivered'] + $byStatus['read'];
            $attempted = $byStatus['sent'] + $delivered + $byStatus['failed'];

            return [
                'channel' => $channel->value,
                'label' => $channel->label(),
                'total' => $byStatus->sum(),
                'by_status' => $byStatus->all(),
                'attempted' => $attempted,
                'delivery_rate' => $attempted ? round($delivered / $attempted, 4) : null,
                'read_rate' => $delivered ? round($byStatus['read'] / $delivered, 4) : null,
                'failure_rate' => $attempted ? round($byStatus['failed'] / $attempted, 4) : null,
                'avg_delivery_seconds' => $latency[$channel->value] ?? null,
            ];
        })->filter(fn (array $row) => $row['total'] > 0)->values()->all();
    }

    /** @return array<string, int> */
    private function latencyByChannel(Builder $query): array
    {
        return $query->whereNotNull('sent_at')->whereNotNull('delivered_at')
            ->get(['channel', 'sent_at', 'delivered_at'])
            ->groupBy(fn (Message $m) => $m->channel->value)
            ->map(fn (Collection $rows) => (int) round($rows->avg(
                fn (Message $m) => $m->sent_at->diffInSeconds($m->delivered_at)
            )))
            ->all();
    }

    private function topErrors(Builder $query): array
    {
        return $query->toBase()
            ->whereNotNull('error_code')
            ->whereIn('status', [MessageStatus::Failed->value, MessageStatus::Skipped->value])
            ->select('error_code', 'channel', DB::raw('count(*) as total'))
            ->groupBy('error_code', 'channel')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['error_code' => $row->error_code, 'channel' => $row->channel, 'total' => (int) $row->total])
            ->all();
    }

    private function hourly(Builder $query, CarbonImmutable $since): array
    {
        $buckets = $query->whereNotNull('sent_at')->pluck('sent_at')
            ->countBy(fn ($sentAt) => $sentAt->startOfHour()->toIso8601String());

        $series = [];
        for ($hour = $since->startOfHour(); $hour->lte(CarbonImmutable::now()); $hour = $hour->addHour()) {
            $series[] = ['hour' => $hour->toIso8601String(), 'sent' => $buckets[$hour->toIso8601String()] ?? 0];
        }

        return $series;
    }
}
