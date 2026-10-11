<?php

namespace App\Messaging\Delivery;

use Carbon\CarbonImmutable;

/**
 * Promotional messages are only sent inside a local-time window. Anything that
 * falls outside is moved to the next opening, never dropped.
 */
class QuietHours
{
    public function __construct(
        private readonly string $timezone,
        private readonly string $start,
        private readonly string $end,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('messaging.quiet_hours.timezone'),
            config('messaging.quiet_hours.start'),
            config('messaging.quiet_hours.end'),
        );
    }

    /** Earliest moment >= $moment that is inside the sending window (returned in UTC). */
    public function nextAllowed(CarbonImmutable $moment): CarbonImmutable
    {
        $local = $moment->setTimezone($this->timezone);
        $opens = $local->setTimeFromTimeString($this->start);
        $closes = $local->setTimeFromTimeString($this->end);

        $allowed = match (true) {
            $local->lt($opens) => $opens,
            $local->gte($closes) => $opens->addDay(),
            default => $local,
        };

        return $allowed->utc();
    }
}
