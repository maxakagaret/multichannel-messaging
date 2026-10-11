<?php

namespace App\Messaging\Drivers;

final readonly class SendReceipt
{
    public function __construct(public string $providerMessageId) {}
}
