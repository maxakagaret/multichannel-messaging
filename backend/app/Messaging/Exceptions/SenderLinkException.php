<?php

namespace App\Messaging\Exceptions;

use RuntimeException;

class SenderLinkException extends RuntimeException
{
    public const MESSAGES = [
        'invalid_token' => 'The link token is unknown, used or expired. Request a new one.',
        'already_linked' => 'The sender already has a linked account. Unlink it first.',
        'quarantined' => 'The sender is quarantined. Link an account after the quarantine ends.',
        'not_supported' => 'This channel cannot link accounts from credentials.',
        'invalid_credentials' => 'The provider rejected the credentials.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? $reason);
    }
}
