<?php

namespace App\Jobs;

use App\Enums\Channel;
use App\Messaging\Delivery\ReceiptProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Plays the role of the provider calling our webhook back. Kept in-process so
 * the demo needs no tunnel; the real HTTP path (signed webhook → same
 * ReceiptProcessor) is covered by WebhookReceiptTest.
 *
 * Outcome is deterministic per recipient: most messages get delivered, a third
 * of those are also read, and a few messenger messages bounce asynchronously
 * ("not registered in the app"), which demonstrates the fallback channel.
 */
class SimulateProviderReceipt implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public readonly string $providerMessageId,
        public readonly Channel $channel,
        public readonly string $recipient,
    ) {}

    public function handle(ReceiptProcessor $receipts): void
    {
        $roll = crc32('receipt|'.$this->channel->value.'|'.$this->recipient) % 100;
        $messenger = in_array($this->channel, [Channel::WhatsApp, Channel::Telegram], true);

        if ($messenger && $roll < 6) {
            $receipts->apply($this->providerMessageId, 'failed', 'not_registered');

            return;
        }

        $receipts->apply($this->providerMessageId, 'delivered');

        if ($this->channel !== Channel::Sms && $roll % 3 === 0) {
            $receipts->apply($this->providerMessageId, 'read');
        }
    }
}
