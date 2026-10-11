<?php

namespace App\Messaging\Delivery;

use App\Enums\MessageStatus;
use App\Events\MessageStatusChanged;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for status changes. Locks the row, enforces monotonic
 * transitions (see MessageStatus::canTransitionTo), stamps the matching
 * timestamp, appends to the audit trail and fires an event after commit.
 *
 * Returns false when the transition was ignored — e.g. a "sent" update that
 * arrives after the provider's "delivered" webhook.
 */
class StatusRecorder
{
    public function transition(
        Message $message,
        MessageStatus $next,
        string $source,
        array $attributes = [],
        array $payload = [],
        ?CarbonImmutable $at = null,
    ): bool {
        $at ??= CarbonImmutable::now();

        $previous = DB::transaction(function () use ($message, $next, $source, $attributes, $payload, $at) {
            /** @var Message $locked */
            $locked = Message::whereKey($message->getKey())->lockForUpdate()->firstOrFail();
            $previous = $locked->status;

            if (! $previous->canTransitionTo($next)) {
                return null;
            }

            $locked->forceFill($attributes + ['status' => $next] + $this->timestampFor($next, $at))->save();

            $locked->events()->create([
                'status' => $next,
                'source' => $source,
                'payload' => $payload ?: null,
                'occurred_at' => $at,
            ]);

            $message->setRawAttributes($locked->getAttributes(), true);

            return $previous;
        });

        if ($previous === null) {
            return false;
        }

        MessageStatusChanged::dispatch($message, $previous, $next);

        return true;
    }

    private function timestampFor(MessageStatus $status, CarbonImmutable $at): array
    {
        return match ($status) {
            MessageStatus::Sent => ['sent_at' => $at],
            MessageStatus::Delivered => ['delivered_at' => $at],
            MessageStatus::Read => ['read_at' => $at],
            MessageStatus::Failed => ['failed_at' => $at],
            default => [],
        };
    }
}
