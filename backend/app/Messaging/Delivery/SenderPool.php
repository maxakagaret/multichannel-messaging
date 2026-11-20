<?php

namespace App\Messaging\Delivery;

use App\Enums\Channel;
use App\Messaging\Exceptions\NoSenderAvailableException;
use App\Messaging\Exceptions\SlotPastDeadlineException;
use App\Messaging\Senders\WarmupDay;
use App\Messaging\Senders\WarmupSchedule;
use App\Models\Sender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Pacing by reservation rather than by sleeping.
 *
 * Each message gets a concrete send time and sender up front, and the job is
 * dispatched with that delay, so workers never block and a burst of 10 000
 * messages turns into an evenly spaced schedule across all available numbers.
 *
 * For every available sender (active, not quarantined) the pool plans the
 * earliest slot that satisfies, in this order:
 *   - the channel throttle after the sender's last slot, plus random jitter;
 *   - the local-time sending window (QuietHours);
 *   - the sender's warm-up day: silent days are skipped entirely, a full daily
 *     limit moves the slot to the next warm-up day, the pace multiplies the gap.
 * The earliest plan wins (ties: least recently used sender).
 *
 * Deadline-bound messages (a reminder that says "tomorrow at 10:00") are not
 * held back by the daily limit or the pace — moving them to the next day makes
 * them wrong, not late — and they keep their own pace pointer, so they do not
 * queue behind the tail of a bulk campaign. If even their earliest slot is past
 * the deadline, nothing is reserved and SlotPastDeadlineException is thrown.
 *
 * All candidate sender rows are locked while the slot is reserved, so parallel
 * dispatchers cannot hand out the same slot or overrun a daily limit.
 */
class SenderPool
{
    private const MAX_STEPS = 400;

    public function __construct(
        private readonly QuietHours $quietHours,
        private readonly WarmupSchedule $warmup,
    ) {}

    /**
     * @throws NoSenderAvailableException
     * @throws SlotPastDeadlineException
     */
    public function reserve(Channel $channel, CarbonImmutable $now, SlotRequest $request = new SlotRequest): SendSlot
    {
        if (! $channel->isThrottled()) {
            $sender = Sender::available($channel, $now)
                ->orderByRaw('last_used_at is null desc')->orderBy('last_used_at')->first();
            $sender?->forceFill(['last_used_at' => $now])->save();

            return new SendSlot($sender, $this->quietHours->nextAllowed($now));
        }

        return DB::transaction(function () use ($channel, $now, $request) {
            $senders = Sender::available($channel, $now)
                ->orderByRaw('last_used_at is null desc')
                ->orderBy('last_used_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($senders->isEmpty()) {
                throw new NoSenderAvailableException($channel);
            }

            $best = null;
            foreach ($senders as $sender) {
                $slot = $this->plan($sender, $channel, $now, $request);
                if ($best === null || $slot->sendAt->lt($best->sendAt)) {
                    $best = $slot;
                }
            }

            if ($request->deadline && $best->sendAt->gt($request->deadline)) {
                throw new SlotPastDeadlineException($best);
            }

            $this->commit($best, $request);

            return $best;
        });
    }

    private function plan(Sender $sender, Channel $channel, CarbonImmutable $now, SlotRequest $request): SendSlot
    {
        $deadlineBound = $request->isDeadlineBound();
        $pointer = $deadlineBound ? $sender->priority_last_used_at : $sender->last_used_at;
        $gap = $this->gapSeconds($channel);
        $reserve = (float) config('messaging.warmup.urgent_reserve');

        $candidate = $pointer ? $pointer->addSeconds($gap)->max($now) : $now;
        $deferredBy = null;

        // Every "continue" moves the candidate strictly forward; warm-up profiles
        // are finite, so the loop settles within a few steps per warm-up day.
        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $candidate = $this->quietHours->nextAllowed($candidate);
            $day = $this->warmup->dayAt($sender, $candidate);

            if ($day?->silent) {
                $candidate = $day->endsAt;
                $deferredBy = 'silent warm-up day';

                continue;
            }

            if ($day && ! $deadlineBound && $this->used($sender, $day) >= $day->capFor($request->priority, $reserve)) {
                $candidate = $day->endsAt;
                $deferredBy = 'daily warm-up limit';

                continue;
            }

            // The pace of the day the slot lands in, not of the day it started from.
            $pace = ($day && ! $deadlineBound) ? $day->pace : 1.0;
            $paced = $pointer ? $pointer->addSeconds((int) ceil($gap * $pace)) : $candidate;

            if ($paced->gt($candidate)) {
                $candidate = $paced;
                $deferredBy ??= 'warm-up pace';

                continue;
            }

            return new SendSlot($sender, $candidate, $day, $deferredBy);
        }

        throw new LogicException("Could not plan a slot for sender {$sender->id}.");
    }

    private function commit(SendSlot $slot, SlotRequest $request): void
    {
        $pointer = $request->isDeadlineBound() ? 'priority_last_used_at' : 'last_used_at';
        $slot->sender->forceFill([$pointer => $slot->sendAt])->save();

        if ($slot->warmupDay) {
            // The sender rows are locked, so increment-or-insert cannot race.
            $key = ['sender_id' => $slot->sender->id, 'day_starts_at' => $slot->warmupDay->startsAt->toDateTimeString()];

            if (! DB::table('sender_daily_usage')->where($key)->increment('reserved')) {
                DB::table('sender_daily_usage')->insert($key + ['reserved' => 1]);
            }
        }
    }

    private function used(Sender $sender, WarmupDay $day): int
    {
        return (int) DB::table('sender_daily_usage')
            ->where('sender_id', $sender->id)
            ->where('day_starts_at', $day->startsAt->toDateTimeString())
            ->value('reserved');
    }

    private function gapSeconds(Channel $channel): int
    {
        $base = (int) config("messaging.throttle_seconds.{$channel->value}");
        $jitter = (int) config('messaging.throttle_jitter_seconds');

        return $base + ($jitter > 0 ? random_int(0, $jitter) : 0);
    }
}
