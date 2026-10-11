<?php

namespace App\Messaging\Delivery;

use App\Enums\MessageStatus;
use App\Models\Message;
use Carbon\CarbonImmutable;

/**
 * Applies a provider delivery receipt. Unknown ids are acknowledged and ignored:
 * providers retry non-2xx webhooks for days, and a receipt for a message we
 * deleted is not an error worth a retry storm.
 */
class ReceiptProcessor
{
    public const STATUSES = ['delivered', 'read', 'failed'];

    public function __construct(private readonly StatusRecorder $status) {}

    /** @return bool whether the receipt changed the message */
    public function apply(string $providerMessageId, string $status, ?string $errorCode = null, ?CarbonImmutable $at = null): bool
    {
        $message = Message::where('provider_message_id', $providerMessageId)->first();

        if (! $message) {
            return false;
        }

        $next = MessageStatus::from($status);
        $attributes = $next === MessageStatus::Failed
            ? ['error_code' => $errorCode ?? 'undelivered', 'error_message' => 'Provider reported the message as undelivered.']
            : [];

        return $this->status->transition($message, $next, 'webhook', $attributes, array_filter([
            'provider_status' => $status,
            'error_code' => $errorCode,
        ]), $at);
    }
}
