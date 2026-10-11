<?php

namespace App\Messaging\Drivers;

use App\Enums\Channel;
use App\Messaging\Contracts\ChannelDriver;
use App\Messaging\Exceptions\PermanentDeliveryException;
use App\Messaging\Exceptions\TransientDeliveryException;
use Illuminate\Support\Str;

/**
 * Stand-in for a real provider. The outcome is derived from a hash of the
 * recipient, so the same contact always behaves the same way:
 *
 *  - a configurable share of recipients is permanently unreachable;
 *  - another share fails with a transient error on the first attempt only
 *    (so the retry path is exercised and then succeeds);
 *  - everybody else is accepted, and — like a real provider — a delivery
 *    receipt arrives asynchronously a few seconds later (SimulateFakeReceipts).
 */
class FakeChannelDriver implements ChannelDriver
{
    public function __construct(
        private readonly Channel $channel,
        private readonly FakeOutbox $outbox,
        private readonly array $config,
    ) {}

    public function send(OutboundMessage $message): SendReceipt
    {
        $bucket = self::bucket($message->channel, $message->recipient);
        $permanent = $this->config['permanent_failure_percent'];
        $transient = $this->config['transient_failure_percent'];

        if ($bucket < $permanent) {
            throw new PermanentDeliveryException(
                $this->channel === Channel::Email ? 'mailbox_does_not_exist' : 'invalid_recipient'
            );
        }

        if ($bucket < $permanent + $transient && $message->attempt <= 1) {
            throw new TransientDeliveryException('provider_timeout');
        }

        $receipt = new SendReceipt($this->channel->value.'-'.Str::uuid());
        $this->outbox->record($message, $receipt);

        // Delivery receipts are emitted by SimulateFakeReceipts once the "sent"
        // status (with this provider id) is stored — see that listener.
        return $receipt;
    }

    /** Stable 0..99 bucket for a recipient in a channel. */
    public static function bucket(Channel $channel, string $recipient): int
    {
        return crc32($channel->value.'|'.$recipient) % 100;
    }
}
