<?php

namespace App\Messaging\Delivery;

use App\Messaging\Senders\WarmupDay;
use App\Models\Sender;
use Carbon\CarbonImmutable;

final readonly class SendSlot
{
    public function __construct(
        public ?Sender $sender,
        public CarbonImmutable $sendAt,
        public ?WarmupDay $warmupDay = null,
        /** Why the slot is later than the plain throttle would put it. */
        public ?string $deferredBy = null,
    ) {}
}
