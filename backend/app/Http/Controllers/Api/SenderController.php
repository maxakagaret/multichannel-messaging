<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Messaging\Exceptions\SenderLinkException;
use App\Messaging\Senders\SenderLinker;
use App\Messaging\Senders\WarmupSchedule;
use App\Models\Sender;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SenderController extends Controller
{
    public function index(WarmupSchedule $warmup): JsonResponse
    {
        $now = CarbonImmutable::now();

        return response()->json([
            'data' => Sender::withCount(['messages', 'quarantines'])->orderBy('channel')->orderBy('name')->get()
                ->map(fn (Sender $s) => $this->present($s, $warmup, $now)),
        ]);
    }

    public function update(Request $request, Sender $sender): JsonResponse
    {
        $sender->update($request->validate(['is_active' => ['required', 'boolean']]));

        return response()->json(['data' => ['id' => $sender->id, 'is_active' => $sender->is_active]]);
    }

    /** One-time token for the tool that uploads the account credentials. */
    public function issueLinkToken(Sender $sender, SenderLinker $linker): JsonResponse
    {
        $issued = $linker->issue($sender, CarbonImmutable::now());

        return response()->json(['data' => [
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
            'upload_url' => url("/api/senders/link/{$issued['token']}"),
        ]], 201);
    }

    public function redeemLinkToken(Request $request, string $token, SenderLinker $linker): JsonResponse
    {
        $credentials = $request->validate(['credentials' => ['required', 'array']])['credentials'];

        try {
            $sender = $linker->redeem($token, $credentials, CarbonImmutable::now());
        } catch (SenderLinkException $e) {
            return response()->json(['reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['id' => $sender->id, 'account_id' => $sender->account_id, 'linked_at' => $sender->linked_at->toIso8601String()]]);
    }

    public function unlink(Sender $sender, SenderLinker $linker): JsonResponse
    {
        $linker->unlink($sender);

        return response()->json(['data' => ['id' => $sender->id, 'linked' => false, 'is_active' => false]]);
    }

    private function present(Sender $s, WarmupSchedule $warmup, CarbonImmutable $now): array
    {
        $day = $warmup->dayAt($s, $now);

        return [
            'id' => $s->id,
            'channel' => $s->channel->value,
            'name' => $s->name,
            'is_active' => $s->is_active,
            'account_id' => $s->account_id,
            'linked' => $s->linked_at !== null,
            'last_used_at' => $s->last_used_at?->toIso8601String(),
            'messages_count' => $s->messages_count,
            'quarantined_until' => $s->isQuarantined($now) ? $s->quarantined_until->toIso8601String() : null,
            'quarantines_count' => $s->quarantines_count,
            // Pending warm-up (starts when the quarantine ends) is shown as a plan, not as today.
            'warmup' => $day ? [
                'profile' => $day->profile,
                'day' => $day->index + 1,
                'days_total' => $day->totalDays,
                'silent' => $day->silent,
                'limit' => $day->limit,
                'used' => (int) DB::table('sender_daily_usage')
                    ->where('sender_id', $s->id)->where('day_starts_at', $day->startsAt->toDateTimeString())->value('reserved'),
                'pace' => $day->pace,
                'day_ends_at' => $day->endsAt->toIso8601String(),
            ] : null,
            'warmup_starts_at' => $s->warmup_started_at?->gt($now) ? $s->warmup_started_at->toIso8601String() : null,
        ];
    }
}
