<?php

namespace App\Messaging\Planning;

use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Messaging\Delivery\StatusRecorder;
use App\Models\Campaign;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class CampaignLauncher
{
    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly DailyBatchScheduler $scheduler,
        private readonly StatusRecorder $status,
    ) {}

    /** @return int planned recipients */
    public function launch(Campaign $campaign, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        if ($campaign->status !== CampaignStatus::Draft) {
            throw new DomainException('Only draft campaigns can be launched.');
        }

        return DB::transaction(function () use ($campaign, $now) {
            $campaign->forceFill(['status' => CampaignStatus::Running, 'launched_at' => $now])->save();

            $startDate = $campaign->starts_on && $campaign->starts_on->gt($now)
                ? $campaign->starts_on
                : $now->setTimezone(config('messaging.quiet_hours.timezone'))->startOfDay();

            return $this->scheduler->spread($campaign, $this->audience->contactIds($campaign), $startDate);
        });
    }

    /**
     * Stops a campaign: unplanned recipients are dropped and messages that are
     * queued but not yet sent are marked skipped (their jobs become no-ops).
     */
    public function cancel(Campaign $campaign): void
    {
        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Running], true)) {
            throw new DomainException('Campaign is already finished.');
        }

        $campaign->recipients()->whereNull('processed_at')->delete();

        $campaign->messages()
            ->whereIn('status', [MessageStatus::Pending, MessageStatus::Queued])
            ->each(fn ($message) => $this->status->transition($message, MessageStatus::Skipped, 'dispatcher', [
                'error_code' => 'campaign_cancelled',
                'error_message' => 'Campaign was cancelled before the message was sent.',
            ]));

        $campaign->forceFill(['status' => CampaignStatus::Cancelled, 'completed_at' => now()])->save();
    }
}
