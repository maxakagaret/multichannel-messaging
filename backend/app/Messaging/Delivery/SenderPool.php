<?php

namespace App\Messaging\Delivery;

use App\Enums\Channel;
use App\Messaging\Exceptions\NoSenderAvailableException;
use App\Models\Sender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pacing by reservation rather than by sleeping.
 *
 * Each message gets a concrete send time and sender up front: the least recently
 * used active sender of the channel is picked (round-robin by recency), and the
 * send time is pushed to "last use + throttle + random jitter". The job is then
 * dispatched with that delay, so workers never block and a burst of 10 000
 * messages turns into an evenly spaced schedule across all available numbers.
 *
 * The sender row is locked while its next slot is reserved, so two dispatchers
 * running in parallel cannot hand out the same slot.
 */
class SenderPool
{
    public function __construct(private readonly QuietHours $quietHours) {}

    /** @throws NoSenderAvailableException */
    public function reserve(Channel $channel, CarbonImmutable $now): SendSlot
    {
        if (! $channel->isThrottled()) {
            $sender = Sender::where('channel', $channel)->where('is_active', true)
                ->orderByRaw('last_used_at is null desc')->orderBy('last_used_at')->first();
            $sender?->forceFill(['last_used_at' => $now])->save();

            return new SendSlot($sender, $this->quietHours->nextAllowed($now));
        }

        return DB::transaction(function () use ($channel, $now) {
            $sender = Sender::where('channel', $channel)
                ->where('is_active', true)
                ->orderByRaw('last_used_at is null desc')
                ->orderBy('last_used_at')
                ->lockForUpdate()
                ->first();

            if (! $sender) {
                throw new NoSenderAvailableException($channel);
            }

            $earliest = $sender->last_used_at
                ? $sender->last_used_at->addSeconds($this->gapSeconds($channel))
                : $now;

            $sendAt = $this->quietHours->nextAllowed($earliest->max($now));

            $sender->forceFill(['last_used_at' => $sendAt])->save();

            return new SendSlot($sender, $sendAt);
        });
    }

    private function gapSeconds(Channel $channel): int
    {
        $base = (int) config("messaging.throttle_seconds.{$channel->value}");
        $jitter = (int) config('messaging.throttle_jitter_seconds');

        return $base + ($jitter > 0 ? random_int(0, $jitter) : 0);
    }
}
