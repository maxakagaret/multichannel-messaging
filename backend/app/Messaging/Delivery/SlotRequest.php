<?php

namespace App\Messaging\Delivery;

use App\Enums\Priority;
use App\Models\Message;
use Carbon\CarbonImmutable;

/** What a message needs from the sender pool. */
final readonly class SlotRequest
{
    public function __construct(
        public Priority $priority = Priority::Bulk,
        public ?CarbonImmutable $deadline = null,
    ) {}

    public static function for(Message $message): self
    {
        return new self($message->priority(), $message->expires_at);
    }

    public function isDeadlineBound(): bool
    {
        return $this->deadline !== null;
    }
}
