<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Message */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'contact' => $this->whenLoaded('contact', fn () => ['id' => $this->contact->id, 'name' => $this->contact->name]),
            'channel' => $this->channel->value,
            'recipient' => $this->recipient,
            'body' => $this->body,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'sender' => $this->whenLoaded('sender', fn () => $this->sender?->name),
            'fallback_of_id' => $this->fallback_of_id,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'status' => $e->status->value,
                'source' => $e->source,
                'payload' => $e->payload,
                'occurred_at' => $e->occurred_at->toIso8601String(),
            ])),
        ];
    }
}
