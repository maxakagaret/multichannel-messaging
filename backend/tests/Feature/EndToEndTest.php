<?php

use App\Enums\Channel;
use App\Enums\MessageStatus;
use App\Messaging\Drivers\FakeChannelDriver;
use App\Models\Campaign;
use App\Models\Message;
use App\Models\Sender;

/*
 * Whole pipeline on the synchronous queue: API launch → scheduler command →
 * delayed jobs (executed immediately by the sync driver) → fake provider →
 * simulated receipts → fallback channel → stats endpoint.
 */
it('delivers a campaign end to end with fallback and stats', function () {
    config([
        'queue.default' => 'sync',
        'messaging.fake.permanent_failure_percent' => 20,
        'messaging.fake.simulate_receipts' => true,
        'messaging.rate_limit_per_minute.whatsapp' => 1000,
    ]);
    Sender::factory()->channel(Channel::WhatsApp)->create();
    Sender::factory()->channel(Channel::Sms)->create();

    // Accepted by the provider and not bounced by the simulated receipt (see SimulateProviderReceipt).
    $good = contactWithTags(['vip'], ['phone' => phoneInBucket(Channel::WhatsApp,
        fn ($bucket, $phone) => $bucket >= 20 && crc32('receipt|whatsapp|'.$phone) % 100 >= 6)]);
    // Permanently rejected on WhatsApp, accepted on SMS.
    $bad = contactWithTags(['vip'], ['phone' => phoneInBucket(Channel::WhatsApp,
        fn ($bucket, $phone) => $bucket < 20 && FakeChannelDriver::bucket(Channel::Sms, $phone) >= 20)]);

    $id = $this->postJson('/api/campaigns', [
        'name' => 'E2E',
        'body' => 'Hi {{first_name}}',
        'channels' => [['channel' => 'whatsapp'], ['channel' => 'sms', 'fallback' => true]],
        'audience' => ['tags' => ['vip']],
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/campaigns/{$id}/launch")->assertOk()->assertJsonPath('planned_recipients', 2);
    $this->artisan('messaging:dispatch-due')->assertSuccessful();

    $goodMessage = Message::where('contact_id', $good->id)->where('channel', 'whatsapp')->sole();
    $badMessage = Message::where('contact_id', $bad->id)->where('channel', 'whatsapp')->sole();
    $fallback = Message::where('fallback_of_id', $badMessage->id)->sole();

    expect($goodMessage->status)->toBeIn([MessageStatus::Delivered, MessageStatus::Read])
        ->and($goodMessage->delivered_at)->not->toBeNull()
        ->and($badMessage->status)->toBe(MessageStatus::Failed)
        ->and($fallback->channel)->toBe(Channel::Sms)
        ->and($fallback->status)->toBe(MessageStatus::Delivered)
        ->and(Campaign::find($id)->status->value)->toBe('completed');

    $this->getJson("/api/campaigns/{$id}/stats")
        ->assertOk()
        ->assertJsonPath('data.planned_recipients', 2)
        ->assertJsonPath('data.fallbacks', 1)
        ->assertJsonCount(2, 'data.channels');
});
