<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Message;

class MessageController extends Controller
{
    public function show(Message $message): MessageResource
    {
        return MessageResource::make($message->load(['contact', 'sender', 'events']));
    }
}
