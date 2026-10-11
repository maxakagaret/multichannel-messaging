<?php

namespace App\Listeners;

use App\Enums\MessageStatus;
use App\Events\MessageStatusChanged;
use App\Messaging\Delivery\MessageDispatcher;
use App\Messaging\Delivery\MessageFactory;

/**
 * Channel cascade: when a message fails (synchronously, after retries, or via an
 * asynchronous "undelivered" receipt) and the campaign chain defines a fallback
 * after that channel — e.g. WhatsApp → SMS — the next channel gets its own
 * message. The factory's idempotency key makes this safe against duplicate
 * failure events.
 */
class ScheduleFallback
{
    public function __construct(
        private readonly MessageFactory $factory,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    public function handle(MessageStatusChanged $event): void
    {
        if ($event->to !== MessageStatus::Failed) {
            return;
        }

        $failed = $event->message->loadMissing(['campaign', 'contact']);
        $next = $failed->campaign?->fallbackAfter($failed->channel);

        if (! $next || ! $failed->contact) {
            return;
        }

        [$message, $created] = $this->factory->forCampaign($failed->campaign, $failed->contact, $next, $failed);

        if ($created && $message->status === MessageStatus::Pending) {
            $this->dispatcher->enqueue($message);
        }
    }
}
