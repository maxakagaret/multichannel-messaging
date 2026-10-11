<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SenderController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Sender::withCount('messages')->orderBy('channel')->orderBy('name')->get()->map(fn (Sender $s) => [
                'id' => $s->id,
                'channel' => $s->channel->value,
                'name' => $s->name,
                'is_active' => $s->is_active,
                'last_used_at' => $s->last_used_at?->toIso8601String(),
                'messages_count' => $s->messages_count,
            ]),
        ]);
    }

    public function update(Request $request, Sender $sender): JsonResponse
    {
        $sender->update($request->validate(['is_active' => ['required', 'boolean']]));

        return response()->json(['data' => ['id' => $sender->id, 'is_active' => $sender->is_active]]);
    }
}
