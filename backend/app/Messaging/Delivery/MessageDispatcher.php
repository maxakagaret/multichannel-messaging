<?php

namespace App\Messaging\Delivery;

use App\Enums\MessageStatus;
use App\Jobs\SendMessage;
use App\Messaging\Exceptions\NoSenderAvailableException;
use App\Messaging\Exceptions\SlotPastDeadlineException;
use App\Models\Message;
use Carbon\CarbonImmutable;

/**
 * Turns a pending message into a delayed queue job: reserves a sender slot,
 * records the plan on the message and dispatches SendMessage for that moment.
 *
 * A message whose earliest slot is past its deadline fails right here, at
 * planning time, rather than a day later in the worker: the fallback channel
 * (and anybody watching the campaign) learns about it now, while it still helps.
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
            $slot = $this->senders->reserve($message->channel, $now, SlotRequest::for($message));
        } catch (NoSenderAvailableException) {
            $this->status->transition($message, MessageStatus::Skipped, 'dispatcher', [
                'error_code' => 'no_sender',
                'error_message' => "No available {$message->channel->label()} sender.",
            ]);

            return;
        } catch (SlotPastDeadlineException $e) {
            $this->status->transition($message, MessageStatus::Failed, 'dispatcher', [
                'error_code' => 'expired',
                'error_message' => $e->getMessage(),
            ], ['earliest_slot' => $e->slot->sendAt->toIso8601String(), 'deferred_by' => $e->slot->deferredBy]);

            return;
        }

        $recorded = $this->status->transition($message, MessageStatus::Queued, 'dispatcher', [
            'sender_id' => $slot->sender?->id,
            'scheduled_for' => $slot->sendAt,
        ], array_filter([
            'send_at' => $slot->sendAt->toIso8601String(),
            'warmup_day' => $slot->warmupDay ? $slot->warmupDay->index + 1 : null,
            'deferred_by' => $slot->deferredBy,
        ]));

        if (! $recorded) {
            return;
        }

        $delay = max(0, $now->diffInSeconds($slot->sendAt, false));

        SendMessage::dispatch($message->id)
            ->onQueue(config('messaging.queue'))
            ->delay((int) ceil($delay));
    }
}
