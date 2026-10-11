<?php

namespace App\Models;

use App\Enums\MessageStatus;
use Illuminate\Database\Eloquent\Model;

class MessageEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => MessageStatus::class,
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
