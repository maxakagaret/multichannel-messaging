<?php

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Messaging\ChannelManager;
use App\Messaging\Delivery\StatusRecorder;
use App\Messaging\Drivers\OutboundMessage;
use App\Messaging\Exceptions\PermanentDeliveryException;
use App\Messaging\Exceptions\SenderRestrictedException;
use App\Messaging\Exceptions\TransientDeliveryException;
use App\Messaging\Senders\CircuitBreaker;
use App\Messaging\Senders\SenderQuarantine;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

/**
 * Sends one message through its channel driver.
 *
 * Retry policy: transient provider errors are rethrown, so the queue retries the
 * job with exponential-ish backoff; permanent errors fail the message at once
 * (no point hammering a provider with an invalid number). When all attempts are
 * used up, failed() marks the message as failed — which in turn may trigger the
 * fallback channel (see ScheduleFallback listener).
 *
 * The job is idempotent: it only acts on messages that are still "queued", so a
 * duplicated or late job (e.g. after a campaign was cancelled) is a no-op.
 *
 * Guards before any provider call, whatever path queued the job:
 *  - an expired message is never sent (stale content is worse than none);
 *  - a message whose sender is quarantined fails and falls back to the next
 *    channel. It is not moved to another number: the recipient knows the
 *    conversation by the number it comes from.
 */
class SendMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Provider errors allowed before giving up. Rate-limit releases are not
     * errors, so they do not burn this budget — retryUntil() bounds them instead.
     */
    public int $maxExceptions = 3;

    public function __construct(public readonly int $messageId) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    /** @return list<int> seconds between attempts after a transient error */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function middleware(): array
    {
        // Safety net on top of SenderPool pacing: a hard per-sender ceiling.
        // Over the limit the job goes back to the queue instead of being sent.
        return [(new RateLimited('messaging-sender'))->releaseAfter(30)];
    }

    public function handle(ChannelManager $channels, StatusRecorder $status, CircuitBreaker $breaker, SenderQuarantine $quarantine): void
    {
        $message = Message::with(['sender', 'contact'])->find($this->messageId);
        $now = CarbonImmutable::now();

        if (! $message || $message->status !== MessageStatus::Queued) {
            return;
        }

        if ($message->isExpired($now)) {
            $status->transition($message, MessageStatus::Failed, 'job', [
                'error_code' => 'expired',
                'error_message' => 'Not sent: the message deadline passed at '.$message->expires_at->toIso8601String().'.',
            ]);

            return;
        }

        if ($message->sender?->isQuarantined($now)) {
            $this->failForQuarantine($message, $status);

            return;
        }

        $message->increment('attempts');

        $outbound = new OutboundMessage(
            messageId: $message->id,
            channel: $message->channel,
            recipient: (string) $message->recipient,
            body: $message->body,
            senderName: $message->sender?->name,
            attempt: $message->attempts,
        );

        $breakerKey = $message->sender_id ? "sender:{$message->sender_id}" : "channel:{$message->channel->value}";

        try {
            $receipt = $breaker->call($breakerKey, fn () => $channels->channel($message->channel)->send($outbound));
        } catch (PermanentDeliveryException $e) {
            $status->transition($message, MessageStatus::Failed, 'job', [
                'error_code' => $e->errorCode,
                'error_message' => $e->getMessage(),
            ], ['attempt' => $message->attempts]);

            return;
        } catch (SenderRestrictedException $e) {
            if ($message->sender && $quarantine->recordRestriction($message->sender, $e->errorCode, $now)) {
                $this->failForQuarantine($message, $status);

                return;
            }

            $this->recordRetry($message, $e);

            throw $e;
        } catch (TransientDeliveryException $e) {
            $this->recordRetry($message, $e);

            throw $e;
        }

        $status->transition($message, MessageStatus::Sent, 'job', [
            'provider_message_id' => $receipt->providerMessageId,
            'error_code' => null,
            'error_message' => null,
        ], ['attempt' => $message->attempts]);
    }

    public function failed(?Throwable $exception): void
    {
        $message = Message::find($this->messageId);

        if (! $message) {
            return;
        }

        app(StatusRecorder::class)->transition($message, MessageStatus::Failed, 'job', [
            'error_code' => $exception instanceof TransientDeliveryException ? $exception->errorCode : 'retries_exhausted',
            'error_message' => 'Gave up after '.$message->attempts.' attempt(s): '.($exception?->getMessage() ?? 'unknown error'),
        ]);
    }

    private function failForQuarantine(Message $message, StatusRecorder $status): void
    {
        $status->transition($message, MessageStatus::Failed, 'job', [
            'error_code' => 'sender_quarantined',
            'error_message' => "Sender {$message->sender->name} is quarantined until "
                .$message->sender->quarantined_until->toIso8601String().'.',
        ]);
    }

    private function recordRetry(Message $message, TransientDeliveryException $e): void
    {
        $message->forceFill(['error_code' => $e->errorCode, 'error_message' => $e->getMessage()])->save();
        $message->events()->create([
            'status' => MessageStatus::Queued,
            'source' => 'job',
            'payload' => ['retry' => true, 'error_code' => $e->errorCode, 'attempt' => $message->attempts],
            'occurred_at' => now(),
        ]);
    }
}
