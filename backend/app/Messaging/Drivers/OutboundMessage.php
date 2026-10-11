<?php

namespace App\Messaging\Drivers;

use App\Enums\Channel;

final readonly class OutboundMessage
{
    public function __construct(
        public int $messageId,
        public Channel $channel,
        public string $recipient,
        public string $body,
        public ?string $senderName,
        public int $attempt,
    ) {}
}
