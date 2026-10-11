<?php

namespace App\Messaging\Delivery;

use App\Enums\MessageStatus;
use App\Jobs\SendMessage;
use App\Messaging\Exceptions\NoSenderAvailableException;
use App\Models\Message;
use Carbon\CarbonImmutable;

/**
 * Turns a pending message into a delayed queue job: reserves a sender slot,
 * records the plan on the message and dispatches SendMessage for that moment.
 */
class MessageDispatcher
{
    public function __construct(
        private readonly SenderPool $senders,
        private readonly StatusRecorder $status,
    ) {}

    public function enqueue(Message $message, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        try {
            $slot = $this->senders->reserve($message->channel, $now);
        } catch (NoSenderAvailableException) {
            $this->status->transition($message, MessageStatus::Skipped, 'dispatcher', [
                'error_code' => 'no_sender',
                'error_message' => "No active {$message->channel->label()} sender configured.",
            ]);

            return;
        }

        $recorded = $this->status->transition($message, MessageStatus::Queued, 'dispatcher', [
            'sender_id' => $slot->sender?->id,
            'scheduled_for' => $slot->sendAt,
        ], ['send_at' => $slot->sendAt->toIso8601String()]);

        if (! $recorded) {
            return;
        }

        $delay = max(0, $now->diffInSeconds($slot->sendAt, false));

        SendMessage::dispatch($message->id)
            ->onQueue(config('messaging.queue'))
            ->delay((int) ceil($delay));
    }
}
