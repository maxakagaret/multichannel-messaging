<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SenderQuarantine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Sender::class);
    }
}
