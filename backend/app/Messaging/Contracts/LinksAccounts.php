<?php

namespace App\Messaging\Contracts;

use App\Messaging\Exceptions\SenderLinkException;
use App\Models\Sender;

/**
 * A driver that can attach a provider account to a sender from credentials
 * handed over by the account owner (a session export, a key bundle, ...).
 */
interface LinksAccounts
{
    /**
     * @return string the provider account id now behind the sender
     *
     * @throws SenderLinkException
     */
    public function link(Sender $sender, #[\SensitiveParameter] array $credentials): string;
}
