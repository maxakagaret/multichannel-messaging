<?php

namespace App\Messaging\Contracts;

use App\Messaging\Drivers\OutboundMessage;
use App\Messaging\Drivers\SendReceipt;
use App\Messaging\Exceptions\PermanentDeliveryException;
use App\Messaging\Exceptions\TransientDeliveryException;

/**
 * One provider integration (SMS gateway, WhatsApp API, Telegram bot, mail service).
 *
 * The contract is deliberately tiny: the queue job owns retries, pacing and status
 * bookkeeping, a driver only translates one message into one provider call and maps
 * the provider's answer into one of three outcomes.
 */
interface ChannelDriver
{
    /**
     * @throws TransientDeliveryException  worth retrying (timeout, 5xx, rate limited)
     * @throws PermanentDeliveryException  retrying will not help (invalid number, blocked)
     */
    public function send(OutboundMessage $message): SendReceipt;
}
