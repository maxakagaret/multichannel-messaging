<?php

namespace App\Messaging\Exceptions;

use App\Enums\Channel;
use RuntimeException;

class NoSenderAvailableException extends RuntimeException
{
    public function __construct(public readonly Channel $channel)
    {
        parent::__construct("No active sender for channel [{$channel->value}].");
    }
}
