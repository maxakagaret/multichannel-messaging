<?php

namespace App\Enums;

/**
 * Message lifecycle. Receipts from providers arrive out of order (a "delivered"
 * webhook can overtake our own "sent" update), so transitions are monotonic:
 * a status is only replaced by one with a higher rank. Terminal failure states
 * cannot be overridden by late success receipts either — except "failed" by a
 * later "delivered", which providers legitimately emit after a retry on their side.
 *
 * "deleted" (the message was removed in the messenger after it arrived) is
 * terminal: a late "delivered" or "read" receipt must not bring it back.
 */
enum MessageStatus: string
{
    case Pending = 'pending';     // created, waiting for a send slot
    case Queued = 'queued';       // job dispatched with a send time
    case Sent = 'sent';           // accepted by the provider
    case Delivered = 'delivered'; // provider receipt: on the device
    case Read = 'read';           // provider receipt: opened
    case Failed = 'failed';       // permanent failure (no more retries)
    case Deleted = 'deleted';     // provider receipt: removed from the chat after sending
    case Skipped = 'skipped';     // never attempted (no address, no consent, ...)

    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Sent => 2,
            self::Failed => 3,
            self::Delivered => 4,
            self::Read => 5,
            self::Deleted => 6,
            self::Skipped => 7,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        if (in_array($this, [self::Skipped, self::Deleted], true)) {
            return false;
        }

        if ($next === self::Skipped) {
            return in_array($this, [self::Pending, self::Queued], true);
        }

        // Only something that reached the chat can be deleted from it.
        if ($next === self::Deleted) {
            return in_array($this, [self::Sent, self::Delivered, self::Read], true);
        }

        return $next->rank() > $this->rank();
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Read, self::Failed, self::Deleted, self::Skipped], true);
    }

    /** Statuses that count as "reached the provider". */
    public static function attempted(): array
    {
        return [self::Sent, self::Delivered, self::Read, self::Failed, self::Deleted];
    }
}
