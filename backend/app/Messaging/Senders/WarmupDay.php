<?php

namespace App\Messaging\Senders;

use App\Enums\Priority;
use Carbon\CarbonImmutable;

/** One day of a sender's warm-up: a 24 h window counted from the warm-up start. */
final readonly class WarmupDay
{
    public function __construct(
        public string $profile,
        public int $index,          // 0-based
        public int $totalDays,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public bool $silent,
        public int $limit,
        public float $pace,
    ) {}

    /**
     * Places of the daily limit open to a priority. Urgent may take the whole
     * limit; bulk stops short of the reserve. The counter is shared, so the
     * total per day never exceeds the limit — urgent just gets in first.
     */
    public function capFor(Priority $priority, float $urgentReserve): int
    {
        if ($this->silent) {
            return 0;
        }

        return $priority === Priority::Urgent
            ? $this->limit
            : (int) floor($this->limit * (1 - $urgentReserve));
    }
}
