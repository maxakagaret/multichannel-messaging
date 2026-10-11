<?php

namespace App\Messaging\Delivery;

use App\Enums\Channel;
use App\Enums\MessageStatus;
use App\Messaging\Rendering\MessageRenderer;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Message;

/**
 * Creates campaign messages idempotently: the idempotency key encodes
 * (campaign, contact, channel[, fallback source]), so re-running a dispatcher
 * after a crash returns the existing row instead of sending twice.
 */
class MessageFactory
{
    public function __construct(private readonly MessageRenderer $renderer) {}

    /** @return array{0: Message, 1: bool} the message and whether it was newly created */
    public function forCampaign(Campaign $campaign, Contact $contact, Channel $channel, ?Message $fallbackOf = null): array
    {
        $key = "c{$campaign->id}:ct{$contact->id}:{$channel->value}".($fallbackOf ? ":fb{$fallbackOf->id}" : '');
        $recipient = $contact->addressFor($channel);

        $message = Message::firstOrCreate(['idempotency_key' => $key], [
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'fallback_of_id' => $fallbackOf?->id,
            'channel' => $channel,
            'recipient' => $recipient,
            'body' => $this->renderer->render($campaign->body, $contact, $channel),
            'status' => $recipient ? MessageStatus::Pending : MessageStatus::Skipped,
            'error_code' => $recipient ? null : 'no_address',
            'error_message' => $recipient ? null : "Contact has no {$channel->label()} address.",
        ]);

        if ($message->wasRecentlyCreated) {
            $message->events()->create([
                'status' => $message->status,
                'source' => 'dispatcher',
                'payload' => $fallbackOf ? ['fallback_of' => $fallbackOf->id] : null,
                'occurred_at' => now(),
            ]);
        }

        return [$message, $message->wasRecentlyCreated];
    }
}
