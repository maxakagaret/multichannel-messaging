<?php

namespace App\Messaging\Senders;

use App\Models\Sender;
use App\Models\SenderQuarantine as QuarantineRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single place where "this account is restricted" turns into a decision.
 * Detection paths (the send job here; a status webhook or a nightly
 * reconciliation in a real system) only report signals and never repeat the logic.
 *
 * Decision: `threshold` signals within `window_hours`, where answers closer
 * than `burst_seconds` to the previous counted one collapse into one. One
 * answer is noise (healthy accounts get it too); a burst from parallel workers
 * is one event; a series spread over hours is a restriction.
 *
 * Effect: no slots until quarantined_until, then the warm-up starts over with
 * the after_quarantine profile. Setting warmup_started_at = quarantined_until
 * means nothing has to wake up and "release" the sender: the schedule simply
 * starts when the quarantine ends.
 */
class SenderQuarantine
{
    /** @return bool whether the sender is quarantined after this signal */
    public function recordRestriction(Sender $sender, string $code, CarbonImmutable $at): bool
    {
        return DB::transaction(function () use ($sender, $code, $at) {
            /** @var Sender $locked */
            $locked = Sender::whereKey($sender->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isQuarantined($at)) {
                $sender->setRawAttributes($locked->getAttributes(), true);

                return true;
            }

            DB::table('sender_restrictions')->insert([
                'sender_id' => $locked->id,
                'code' => $code,
                'occurred_at' => $at->toDateTimeString(),
            ]);

            $signals = $this->signals($locked, $at);

            if ($signals < (int) config('messaging.quarantine.threshold')) {
                return false;
            }

            $this->quarantine($locked, $code, $signals, $at);
            $sender->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /** Signals in the window after collapsing bursts. */
    public function signals(Sender $sender, CarbonImmutable $at): int
    {
        $burst = (int) config('messaging.quarantine.burst_seconds');
        $since = $at->subHours((int) config('messaging.quarantine.window_hours'));

        // Only signals after the last quarantine count: the series that caused it is spent.
        $lastEnd = $sender->quarantines()->max('ends_at');
        if ($lastEnd && CarbonImmutable::parse($lastEnd)->gt($since)) {
            $since = CarbonImmutable::parse($lastEnd);
        }

        $count = 0;
        $lastCounted = null;

        DB::table('sender_restrictions')
            ->where('sender_id', $sender->id)
            ->where('occurred_at', '>=', $since->toDateTimeString())
            ->orderBy('occurred_at')
            ->pluck('occurred_at')
            ->each(function (string $raw) use (&$count, &$lastCounted, $burst) {
                $occurred = CarbonImmutable::parse($raw);
                if ($lastCounted === null || $lastCounted->diffInSeconds($occurred) > $burst) {
                    $count++;
                    $lastCounted = $occurred;
                }
            });

        return $count;
    }

    private function quarantine(Sender $sender, string $reason, int $signals, CarbonImmutable $at): void
    {
        $until = $at->addHours((int) config('messaging.quarantine.duration_hours'));

        $sender->startWarmup(Sender::WARMUP_AFTER_QUARANTINE, $until)
            ->forceFill(['quarantined_until' => $until])
            ->save();

        QuarantineRecord::create([
            'sender_id' => $sender->id,
            'account_id' => $sender->account_id,
            'reason' => $reason,
            'signals' => $signals,
            'started_at' => $at,
            'ends_at' => $until,
        ]);

        Log::warning('Sender quarantined', [
            'sender_id' => $sender->id,
            'reason' => $reason,
            'signals' => $signals,
            'until' => $until->toIso8601String(),
            'previous_quarantines' => $sender->quarantines()->count() - 1,
        ]);
    }
}
