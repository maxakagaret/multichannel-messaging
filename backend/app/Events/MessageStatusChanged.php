<?php

namespace App\Events;

use App\Enums\MessageStatus;
use App\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;

class MessageStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Message $message,
        public readonly MessageStatus $from,
        public readonly MessageStatus $to,
    ) {}
}
