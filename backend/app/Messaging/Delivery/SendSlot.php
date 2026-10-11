<?php

namespace App\Messaging\Delivery;

use App\Models\Sender;
use Carbon\CarbonImmutable;

final readonly class SendSlot
{
    public function __construct(
        public ?Sender $sender,
        public CarbonImmutable $sendAt,
    ) {}
}
