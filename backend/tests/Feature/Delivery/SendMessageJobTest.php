<?php

use App\Enums\Channel;
use App\Enums\MessageStatus;
use App\Jobs\SendMessage;
use App\Messaging\Delivery\MessageFactory;
use App\Messaging\Delivery\StatusRecorder;
use App\Messaging\Drivers\FakeOutbox;
use App\Messaging\Exceptions\TransientDeliveryException;
use App\Models\Campaign;
use App\Models\Message;
use App\Models\Sender;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Sender::factory()->channel(Channel::WhatsApp)->create();
    Sender::factory()->channel(Channel::Sms)->create();

    $this->campaign = Campaign::factory()->channels([
        ['channel' => 'whatsapp', 'fallback' => false],
        ['channel' => 'sms', 'fallback' => true],
    ])->create();
});

function queuedMessage(Campaign $campaign, string $phone): Message
{
    [$message] = app(MessageFactory::class)->forCampaign($campaign, contactWithTags([], ['phone' => $phone]), Channel::WhatsApp);
    app(StatusRecorder::class)->transition($message, MessageStatus::Queued, 'test');

    return $message->refresh();
}

function runJob(Message $message): void
{
    app()->call([new SendMessage($message->id), 'handle']);
}

it('sends through the channel driver and stores the provider id', function () {
    $message = queuedMessage($this->campaign, '+15550100001');

    runJob($message);

    $message->refresh();
    expect($message->status)->toBe(MessageStatus::Sent)
        ->and($message->provider_message_id)->toStartWith('whatsapp-')
        ->and($message->attempts)->toBe(1)
        ->and(app(FakeOutbox::class)->count())->toBe(1)
        ->and($message->events->pluck('status')->map->value->all())->toBe(['pending', 'queued', 'sent']);
});

it('fails permanently without retrying and falls back to the next channel', function () {
    config(['messaging.fake.permanent_failure_percent' => 100]);
    $message = queuedMessage($this->campaign, '+15550100002');

    runJob($message);

    expect($message->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->error_code)->toBe('invalid_recipient');

    $fallback = Message::where('fallback_of_id', $message->id)->sole();
    expect($fallback->channel)->toBe(Channel::Sms)
        ->and($fallback->status)->toBe(MessageStatus::Queued);
    Queue::assertPushed(SendMessage::class, fn ($job) => $job->messageId === $fallback->id);
});

it('rethrows transient errors so the queue retries, then succeeds', function () {
    config(['messaging.fake.transient_failure_percent' => 100]);
    $message = queuedMessage($this->campaign, '+15550100003');

    expect(fn () => runJob($message))->toThrow(TransientDeliveryException::class);
    expect($message->refresh()->status)->toBe(MessageStatus::Queued)
        ->and($message->error_code)->toBe('provider_timeout');

    // The fake provider only times out on the first attempt.
    runJob($message);

    expect($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($message->attempts)->toBe(2)
        ->and($message->error_code)->toBeNull();
});

it('marks the message failed when retries are exhausted', function () {
    $message = queuedMessage($this->campaign, '+15550100004');

    (new SendMessage($message->id))->failed(new TransientDeliveryException('provider_timeout'));

    expect($message->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->error_code)->toBe('provider_timeout')
        ->and(Message::where('fallback_of_id', $message->id)->exists())->toBeTrue();
});

it('is a no-op for messages that are no longer queued', function () {
    $message = queuedMessage($this->campaign, '+15550100005');
    app(StatusRecorder::class)->transition($message, MessageStatus::Skipped, 'test');

    runJob($message);

    expect($message->refresh()->status)->toBe(MessageStatus::Skipped)
        ->and(app(FakeOutbox::class)->count())->toBe(0);
});

it('creates the fallback only once even if failure is reported twice', function () {
    config(['messaging.fake.permanent_failure_percent' => 100]);
    $message = queuedMessage($this->campaign, '+15550100006');

    runJob($message);
    (new SendMessage($message->id))->failed(new RuntimeException('duplicate failure event'));

    expect(Message::where('fallback_of_id', $message->id)->count())->toBe(1);
});
