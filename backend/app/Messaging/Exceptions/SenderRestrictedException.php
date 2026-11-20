<?php

namespace App\Messaging\Exceptions;

/**
 * The provider refused because the messenger restricted the sending account
 * (not the recipient). Transient for the message, but a health signal for the
 * sender — see SenderQuarantine.
 */
class SenderRestrictedException extends TransientDeliveryException
{
    public function __construct(string $message = 'The messenger restricted this sender account.')
    {
        parent::__construct('sender_restricted', $message);
    }
}
