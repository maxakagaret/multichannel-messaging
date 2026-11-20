<?php

namespace App\Messaging\Exceptions;

use App\Messaging\Delivery\SendSlot;
use RuntimeException;

/** The earliest slot any sender can offer is later than the message deadline. */
class SlotPastDeadlineException extends RuntimeException
{
    public function __construct(public readonly SendSlot $slot)
    {
        parent::__construct('Earliest slot '.$slot->sendAt->toIso8601String().' is past the deadline'
            .($slot->deferredBy ? " ({$slot->deferredBy})" : '').'.');
    }
}
