<?php

namespace App\Http\Controllers\Api;

use App\Enums\Channel;
use App\Http\Controllers\Controller;
use App\Messaging\Delivery\ReceiptProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WebhookController extends Controller
{
    /**
     * Delivery receipts. Always 202 for well-formed payloads — including unknown
     * message ids and out-of-order receipts — so providers stop retrying.
     */
    public function receipts(Request $request, Channel $channel, ReceiptProcessor $receipts): JsonResponse
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:500'],
            'events.*.provider_message_id' => ['required', 'string', 'max:191'],
            'events.*.status' => ['required', Rule::in(ReceiptProcessor::STATUSES)],
            'events.*.error_code' => ['nullable', 'string', 'max:64'],
            'events.*.occurred_at' => ['nullable', 'date'],
        ]);

        $applied = 0;
        foreach ($data['events'] as $event) {
            $applied += (int) $receipts->apply(
                $event['provider_message_id'],
                $event['status'],
                $event['error_code'] ?? null,
                isset($event['occurred_at']) ? CarbonImmutable::parse($event['occurred_at']) : null,
            );
        }

        return response()->json(['received' => count($data['events']), 'applied' => $applied], 202);
    }
}
