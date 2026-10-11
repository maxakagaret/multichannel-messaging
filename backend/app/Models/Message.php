<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\MessageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => MessageStatus::class,
            'scheduled_for' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Sender::class);
    }

    public function fallbackOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fallback_of_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MessageEvent::class)->orderBy('occurred_at')->orderBy('id');
    }
}
