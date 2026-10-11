<?php

namespace App\Messaging\Planning;

use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Messaging\Delivery\MessageDispatcher;
use App\Messaging\Delivery\MessageFactory;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs every minute: turns today's planned recipients into messages and queues them.
 *
 * Invariant: every recipient picked up here is marked processed, whatever happens
 * to it. A recipient that cannot be sent (no address, broken template, exception)
 * is recorded as such and never re-selected — otherwise a minutely cron would
 * re-process the same rows forever, flooding logs and, worse, retrying sends.
 */
class DueRecipientsDispatcher
{
    public function __construct(
        private readonly MessageFactory $factory,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    /** @return int messages queued */
    public function dispatch(?CarbonImmutable $now = null, int $batchSize = 200): int
    {
        $now ??= CarbonImmutable::now();
        $today = $now->setTimezone(config('messaging.quiet_hours.timezone'))->toDateString();
        $queued = 0;

        Campaign::where('status', CampaignStatus::Running)->each(function (Campaign $campaign) use ($today, $now, $batchSize, &$queued) {
            CampaignRecipient::with('contact')
                ->where('campaign_id', $campaign->id)
                ->whereNull('processed_at')
                ->where('send_on', '<=', $today)
                ->orderBy('id')
                ->limit($batchSize)
                ->get()
                ->each(function (CampaignRecipient $recipient) use ($campaign, $now, &$queued) {
                    try {
                        foreach ($campaign->primaryChannels() as $channel) {
                            [$message, $created] = $this->factory->forCampaign($campaign, $recipient->contact, $channel);

                            if ($created && $message->status === MessageStatus::Pending) {
                                $this->dispatcher->enqueue($message, $now);
                                $queued++;
                            }
                        }
                    } catch (Throwable $e) {
                        Log::error('Recipient dispatch failed', [
                            'campaign_id' => $campaign->id,
                            'contact_id' => $recipient->contact_id,
                            'error' => $e->getMessage(),
                        ]);
                    } finally {
                        $recipient->forceFill(['processed_at' => $now])->save();
                    }
                });

            $this->completeIfDone($campaign, $now);
        });

        return $queued;
    }

    private function completeIfDone(Campaign $campaign, CarbonImmutable $now): void
    {
        if (! $campaign->recipients()->whereNull('processed_at')->exists()) {
            $campaign->forceFill(['status' => CampaignStatus::Completed, 'completed_at' => $now])->save();
        }
    }
}
