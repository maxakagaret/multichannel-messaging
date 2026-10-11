<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\Channel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'audience' => 'array',
            'status' => CampaignStatus::class,
            'starts_on' => 'immutable_date',
            'launched_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Channels sent immediately: the leading part of the chain up to the first
     * fallback entry. Fallback channels are used only when the previous one fails.
     *
     * @return list<Channel>
     */
    public function primaryChannels(): array
    {
        $primary = [];
        foreach ($this->channels as $index => $entry) {
            if ($index > 0 && ($entry['fallback'] ?? false)) {
                break;
            }
            $primary[] = Channel::from($entry['channel']);
        }

        return $primary;
    }

    /** Next fallback channel after $failed in the chain, if the chain defines one. */
    public function fallbackAfter(Channel $failed): ?Channel
    {
        $chain = array_values($this->channels);
        foreach ($chain as $index => $entry) {
            if ($entry['channel'] !== $failed->value) {
                continue;
            }
            $next = $chain[$index + 1] ?? null;

            return ($next && ($next['fallback'] ?? false)) ? Channel::from($next['channel']) : null;
        }

        return null;
    }
}
