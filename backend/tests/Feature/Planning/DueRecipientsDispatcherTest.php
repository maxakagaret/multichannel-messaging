<?php

use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Enums\MessageStatus;
use App\Jobs\SendMessage;
use App\Messaging\Planning\CampaignLauncher;
use App\Messaging\Planning\DueRecipientsDispatcher;
use App\Messaging\Rendering\MessageRenderer;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Message;
use App\Models\Sender;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Sender::factory()->channel(Channel::WhatsApp)->create();
});

it('queues one message per primary channel for recipients due today', function () {
    $campaign = Campaign::factory()->channels([
        ['channel' => 'whatsapp'], ['channel' => 'email'], ['channel' => 'sms', 'fallback' => true],
    ])->create();
    contactWithTags();
    contactWithTags();
    app(CampaignLauncher::class)->launch($campaign);

    $queued = app(DueRecipientsDispatcher::class)->dispatch();

    expect($queued)->toBe(4)
        ->and(Message::pluck('channel')->map->value->sort()->values()->all())->toBe(['email', 'email', 'whatsapp', 'whatsapp'])
        ->and(Message::where('channel', 'sms')->exists())->toBeFalse();
    Queue::assertPushed(SendMessage::class, 4);
});

it('leaves recipients planned for later days alone', function () {
    $campaign = Campaign::factory()->channels([['channel' => 'whatsapp']])->create(['daily_cap' => 1]);
    contactWithTags();
    contactWithTags();
    app(CampaignLauncher::class)->launch($campaign);

    app(DueRecipientsDispatcher::class)->dispatch();

    expect(CampaignRecipient::whereNull('processed_at')->count())->toBe(1)
        ->and($campaign->refresh()->status)->toBe(CampaignStatus::Running);
});

it('marks recipients processed even when they cannot be reached', function () {
    $campaign = Campaign::factory()->channels([['channel' => 'whatsapp'], ['channel' => 'email']])->create();
    $contact = contactWithTags([], ['phone' => null]);
    app(CampaignLauncher::class)->launch($campaign);

    app(DueRecipientsDispatcher::class)->dispatch();

    expect(CampaignRecipient::whereNull('processed_at')->exists())->toBeFalse()
        ->and(Message::where('channel', 'whatsapp')->sole()->status)->toBe(MessageStatus::Skipped)
        ->and(Message::where('channel', 'email')->sole()->status)->toBe(MessageStatus::Queued);
});

it('marks recipients processed even when rendering blows up', function () {
    $campaign = Campaign::factory()->channels([['channel' => 'whatsapp']])->create();
    contactWithTags();
    app(CampaignLauncher::class)->launch($campaign);
    $this->mock(MessageRenderer::class)->shouldReceive('render')->andThrow(new RuntimeException('template broken'));

    app(DueRecipientsDispatcher::class)->dispatch();

    expect(CampaignRecipient::whereNull('processed_at')->exists())->toBeFalse()
        ->and(Message::count())->toBe(0);
});

it('is idempotent when the same recipient is dispatched twice', function () {
    $campaign = Campaign::factory()->channels([['channel' => 'whatsapp']])->create();
    contactWithTags();
    app(CampaignLauncher::class)->launch($campaign);
    app(DueRecipientsDispatcher::class)->dispatch();

    // Simulate a crash between "message created" and "recipient processed".
    CampaignRecipient::query()->update(['processed_at' => null]);
    $queuedAgain = app(DueRecipientsDispatcher::class)->dispatch();

    expect($queuedAgain)->toBe(0)
        ->and(Message::count())->toBe(1);
    Queue::assertPushed(SendMessage::class, 1);
});

it('completes the campaign once every recipient is dispatched', function () {
    $campaign = Campaign::factory()->channels([['channel' => 'whatsapp']])->create();
    contactWithTags();
    app(CampaignLauncher::class)->launch($campaign);

    app(DueRecipientsDispatcher::class)->dispatch();

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Completed)
        ->and($campaign->completed_at)->not->toBeNull();
});

it('cancelling a campaign skips queued messages and drops unplanned recipients', function () {
    $campaign = Campaign::factory()->channels([['channel' => 'whatsapp']])->create(['daily_cap' => 1]);
    contactWithTags();
    contactWithTags();
    app(CampaignLauncher::class)->launch($campaign);
    app(DueRecipientsDispatcher::class)->dispatch();

    app(CampaignLauncher::class)->cancel($campaign);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Cancelled)
        ->and(CampaignRecipient::whereNull('processed_at')->count())->toBe(0)
        ->and(Message::sole()->status)->toBe(MessageStatus::Skipped);
});
