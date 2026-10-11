<?php

use App\Enums\Channel;
use App\Enums\MessageStatus;
use App\Messaging\Delivery\MessageFactory;
use App\Messaging\Delivery\StatusRecorder;
use App\Models\Campaign;
use App\Models\Message;
use App\Models\Sender;
use Illuminate\Support\Facades\Queue;

function signedReceipt(array $events, ?string $secret = null): \Illuminate\Testing\TestResponse
{
    $body = json_encode(['events' => $events]);
    $signature = hash_hmac('sha256', $body, $secret ?? config('messaging.webhook_secret'));

    return test()->call('POST', '/api/webhooks/whatsapp/receipts', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SIGNATURE' => $signature,
    ], $body);
}

beforeEach(function () {
    Queue::fake();
    Sender::factory()->channel(Channel::Sms)->create();
    $campaign = Campaign::factory()->channels([
        ['channel' => 'whatsapp'], ['channel' => 'sms', 'fallback' => true],
    ])->create();

    [$message] = app(MessageFactory::class)->forCampaign($campaign, contactWithTags(), Channel::WhatsApp);
    app(StatusRecorder::class)->transition($message, MessageStatus::Queued, 'test');
    app(StatusRecorder::class)->transition($message, MessageStatus::Sent, 'test', ['provider_message_id' => 'wa-123']);
    $this->message = $message->refresh();
});

it('rejects receipts with a bad signature', function () {
    signedReceipt([['provider_message_id' => 'wa-123', 'status' => 'delivered']], 'wrong-secret')
        ->assertUnauthorized();

    expect($this->message->refresh()->status)->toBe(MessageStatus::Sent);
});

it('applies delivered and read receipts', function () {
    signedReceipt([
        ['provider_message_id' => 'wa-123', 'status' => 'delivered'],
        ['provider_message_id' => 'wa-123', 'status' => 'read'],
    ])->assertAccepted()->assertJson(['received' => 2, 'applied' => 2]);

    $message = $this->message->refresh();
    expect($message->status)->toBe(MessageStatus::Read)
        ->and($message->delivered_at)->not->toBeNull()
        ->and($message->read_at)->not->toBeNull();
});

it('ignores out-of-order receipts instead of downgrading', function () {
    signedReceipt([
        ['provider_message_id' => 'wa-123', 'status' => 'read'],
        ['provider_message_id' => 'wa-123', 'status' => 'delivered'],
    ])->assertAccepted()->assertJson(['applied' => 1]);

    expect($this->message->refresh()->status)->toBe(MessageStatus::Read);
});

it('acknowledges unknown message ids so the provider stops retrying', function () {
    signedReceipt([['provider_message_id' => 'unknown', 'status' => 'delivered']])
        ->assertAccepted()->assertJson(['applied' => 0]);
});

it('starts the fallback channel when a message bounces asynchronously', function () {
    signedReceipt([['provider_message_id' => 'wa-123', 'status' => 'failed', 'error_code' => 'not_registered']])
        ->assertAccepted();

    expect($this->message->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($this->message->error_code)->toBe('not_registered')
        ->and(Message::where('fallback_of_id', $this->message->id)->sole()->channel)->toBe(Channel::Sms);
});

it('validates the payload', function () {
    signedReceipt([['provider_message_id' => 'wa-123', 'status' => 'exploded']])
        ->assertUnprocessable()->assertJsonValidationErrors('events.0.status');
});
