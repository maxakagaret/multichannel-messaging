<?php

namespace App\Models;

use App\Enums\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sender extends Model
{
    use HasFactory;

    public const WARMUP_NEW = 'new';

    public const WARMUP_AFTER_QUARANTINE = 'after_quarantine';

    protected $guarded = [];

    protected $hidden = ['link_token_hash'];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function quarantines(): HasMany
    {
        return $this->hasMany(SenderQuarantine::class);
    }

    /** Senders the pool may hand slots to right now. */
    public function scopeAvailable(Builder $query, Channel $channel, CarbonImmutable $now): void
    {
        $query->where('channel', $channel)
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('quarantined_until')->orWhere('quarantined_until', '<=', $now));
    }

    public function isQuarantined(CarbonImmutable $now): bool
    {
        return $this->quarantined_until !== null && $this->quarantined_until->gt($now);
    }

    /** Sets the warm-up start; the caller saves, together with its other changes. */
    public function startWarmup(string $profile, CarbonImmutable $at): static
    {
        return $this->forceFill(['warmup_profile' => $profile, 'warmup_started_at' => $at]);
    }

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'is_active' => 'boolean',
            'last_used_at' => 'immutable_datetime',
            'priority_last_used_at' => 'immutable_datetime',
            'warmup_started_at' => 'immutable_datetime',
            'quarantined_until' => 'immutable_datetime',
            'linked_at' => 'immutable_datetime',
            'link_token_expires_at' => 'immutable_datetime',
        ];
    }
}
