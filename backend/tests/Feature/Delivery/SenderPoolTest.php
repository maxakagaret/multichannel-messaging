<?php

use App\Enums\Channel;
use App\Enums\MessageStatus;
use App\Messaging\Delivery\MessageDispatcher;
use App\Messaging\Delivery\MessageFactory;
use App\Messaging\Delivery\SenderPool;
use App\Messaging\Exceptions\NoSenderAvailableException;
use App\Models\Campaign;
use App\Models\Sender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

it('rotates throttled traffic across senders, least recently used first', function () {
    config(['messaging.throttle_seconds.whatsapp' => 20]);
    $a = Sender::factory()->channel(Channel::WhatsApp)->create(['name' => 'A']);
    $b = Sender::factory()->channel(Channel::WhatsApp)->create(['name' => 'B']);
    $pool = app(SenderPool::class);
    $now = CarbonImmutable::now();

    $slots = collect(range(1, 4))->map(fn () => $pool->reserve(Channel::WhatsApp, $now));

    expect($slots->map(fn ($s) => $s->sender->name)->all())->toBe(['A', 'B', 'A', 'B'])
        ->and($slots->map(fn ($s) => $now->diffInSeconds($s->sendAt))->all())->toEqual([0, 0, 20, 20]);
});

it('spaces messages from one sender by the channel throttle', function () {
    config(['messaging.throttle_seconds.whatsapp' => 30]);
    Sender::factory()->channel(Channel::WhatsApp)->create();
    $pool = app(SenderPool::class);
    $now = CarbonImmutable::now();

    $times = collect(range(1, 3))->map(fn () => $now->diffInSeconds($pool->reserve(Channel::WhatsApp, $now)->sendAt));

    expect($times->all())->toEqual([0, 30, 60]);
});

it('pushes slots outside the sending window to the next opening', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 22:30', 'UTC'));
    Sender::factory()->channel(Channel::WhatsApp)->create();

    $slot = app(SenderPool::class)->reserve(Channel::WhatsApp, CarbonImmutable::now());

    expect($slot->sendAt->toDateTimeString())->toBe('2026-03-03 09:00:00');
});

it('ignores inactive senders', function () {
    Sender::factory()->channel(Channel::WhatsApp)->create(['is_active' => false]);

    app(SenderPool::class)->reserve(Channel::WhatsApp, CarbonImmutable::now());
})->throws(NoSenderAvailableException::class);

it('does not throttle channels without a throttle and works without senders', function () {
    $slot = app(SenderPool::class)->reserve(Channel::Email, CarbonImmutable::now());

    expect($slot->sender)->toBeNull()
        ->and($slot->sendAt->toDateTimeString())->toBe('2026-03-02 12:00:00');
});

it('skips the message when a throttled channel has no sender', function () {
    Queue::fake();
    $campaign = Campaign::factory()->channels([['channel' => 'whatsapp']])->create();
    [$message] = app(MessageFactory::class)->forCampaign($campaign, contactWithTags(), Channel::WhatsApp);

    app(MessageDispatcher::class)->enqueue($message);

    expect($message->refresh()->status)->toBe(MessageStatus::Skipped)
        ->and($message->error_code)->toBe('no_sender');
    Queue::assertNothingPushed();
});

it('dispatches the send job with the reserved delay', function () {
    Queue::fake();
    config(['messaging.throttle_seconds.whatsapp' => 45]);
    Sender::factory()->channel(Channel::WhatsApp)->create(['last_used_at' => CarbonImmutable::now()]);
    $campaign = Campaign::factory()->channels([['channel' => 'whatsapp']])->create();
    [$message] = app(MessageFactory::class)->forCampaign($campaign, contactWithTags(), Channel::WhatsApp);

    app(MessageDispatcher::class)->enqueue($message);

    expect($message->refresh()->status)->toBe(MessageStatus::Queued)
        ->and($message->scheduled_for->toDateTimeString())->toBe('2026-03-02 12:00:45');
    Queue::assertPushedOn('messaging', \App\Jobs\SendMessage::class, fn ($job) => $job->messageId === $message->id && $job->delay === 45);
});
