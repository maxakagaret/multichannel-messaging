<?php

namespace App\Messaging\Senders;

use App\Models\Sender;
use Carbon\CarbonImmutable;

/**
 * Reads the warm-up profile of a sender (config messaging.warmup.profiles).
 *
 * Days are counted from warmup_started_at in 24 h steps, not by calendar date:
 * an account linked at 23:00 would otherwise get its second day an hour later.
 */
class WarmupSchedule
{
    /** @return WarmupDay|null null when the sender is not warming up at that moment */
    public function dayAt(Sender $sender, CarbonImmutable $at): ?WarmupDay
    {
        if (! $sender->warmup_profile || ! $sender->warmup_started_at || $at->lt($sender->warmup_started_at)) {
            return null;
        }

        $days = config("messaging.warmup.profiles.{$sender->warmup_profile}", []);
        $index = intdiv((int) $sender->warmup_started_at->diffInSeconds($at), 86400);

        if (! isset($days[$index])) {
            return null;
        }

        $startsAt = $sender->warmup_started_at->addDays($index);
        $day = $days[$index];

        return new WarmupDay(
            profile: $sender->warmup_profile,
            index: $index,
            totalDays: count($days),
            startsAt: $startsAt,
            endsAt: $startsAt->addDay(),
            silent: (bool) ($day['silent'] ?? false),
            limit: (int) ($day['limit'] ?? 0),
            pace: (float) ($day['pace'] ?? 1),
        );
    }
}
