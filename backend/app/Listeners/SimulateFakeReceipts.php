<?php

namespace App\Listeners;

use App\Enums\MessageStatus;
use App\Events\MessageStatusChanged;
use App\Jobs\SimulateProviderReceipt;

/**
 * Demo-only: when a message handled by a fake driver is recorded as sent,
 * schedule the provider's delivery receipt. Hooking the "sent" event (rather
 * than emitting from inside the driver) guarantees the provider id is already
 * stored when the receipt arrives — exactly the order a real provider gives.
 */
class SimulateFakeReceipts
{
    public function handle(MessageStatusChanged $event): void
    {
        $message = $event->message;

        if ($event->to !== MessageStatus::Sent
            || config("messaging.drivers.{$message->channel->value}") !== 'fake'
            || ! config('messaging.fake.simulate_receipts')
            || ! $message->provider_message_id) {
            return;
        }

        SimulateProviderReceipt::dispatch($message->provider_message_id, $message->channel, (string) $message->recipient)
            ->onQueue(config('messaging.queue'))
            ->delay(now()->addSeconds((int) config('messaging.fake.receipt_delay_seconds')));
    }
}
