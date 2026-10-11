<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Campaign */
class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'body' => $this->body,
            'channels' => $this->channels,
            'audience' => $this->audience,
            'daily_cap' => $this->daily_cap,
            'status' => $this->status->value,
            'starts_on' => $this->starts_on?->toDateString(),
            'launched_at' => $this->launched_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'recipients_count' => $this->whenCounted('recipients'),
            'messages_count' => $this->whenCounted('messages'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
