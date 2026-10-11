<?php

namespace App\Messaging\Exceptions;

use RuntimeException;

class PermanentDeliveryException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message ?: $errorCode);
    }
}
