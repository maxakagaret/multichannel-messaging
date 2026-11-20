<?php

namespace App\Messaging\Drivers;

use App\Enums\Channel;
use App\Messaging\Contracts\ChannelDriver;
use App\Messaging\Contracts\LinksAccounts;
use App\Messaging\Exceptions\PermanentDeliveryException;
use App\Messaging\Exceptions\SenderLinkException;
use App\Messaging\Exceptions\SenderRestrictedException;
use App\Messaging\Exceptions\TransientDeliveryException;
use App\Models\Sender;
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
 *
 * Senders listed in fake.restricted_senders behave like an account the
 * messenger has limited: every send is refused with "sender_restricted".
 */
class FakeChannelDriver implements ChannelDriver, LinksAccounts
{
    public function __construct(
        private readonly Channel $channel,
        private readonly FakeOutbox $outbox,
        private readonly array $config,
    ) {}

    public function send(OutboundMessage $message): SendReceipt
    {
        if ($message->senderName !== null && in_array($message->senderName, $this->config['restricted_senders'] ?? [], true)) {
            throw new SenderRestrictedException;
        }

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

    /** Accepts {"account": "<id>", "keys": [...]}; a real driver passes them to the provider. */
    public function link(Sender $sender, #[\SensitiveParameter] array $credentials): string
    {
        $account = $credentials['account'] ?? null;

        if (! is_string($account) || $account === '' || empty($credentials['keys']) || ! is_array($credentials['keys'])) {
            throw new SenderLinkException('invalid_credentials');
        }

        return $account;
    }

    /** Stable 0..99 bucket for a recipient in a channel. */
    public static function bucket(Channel $channel, string $recipient): int
    {
        return crc32($channel->value.'|'.$recipient) % 100;
    }
}
