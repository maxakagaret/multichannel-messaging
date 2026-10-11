<?php

use App\Enums\CampaignStatus;
use App\Models\Campaign;

$valid = fn (array $overrides = []) => array_replace_recursive([
    'name' => 'Spring promo',
    'body' => 'Hi {{first_name}}, **-15%** this week',
    'channels' => [
        ['channel' => 'whatsapp', 'fallback' => false],
        ['channel' => 'sms', 'fallback' => true],
    ],
    'audience' => ['tags' => ['vip']],
    'daily_cap' => 25,
], $overrides);

it('creates a draft campaign', function () use ($valid) {
    $this->postJson('/api/campaigns', $valid())
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.channels.1', ['channel' => 'sms', 'fallback' => true])
        ->assertJsonPath('data.daily_cap', 25);
});

it('rejects a chain that starts with a fallback or repeats a channel', function () use ($valid) {
    $this->postJson('/api/campaigns', $valid(['channels' => [
        ['channel' => 'sms', 'fallback' => true],
        ['channel' => 'sms', 'fallback' => false],
    ]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channels.0.fallback', 'channels.1.channel']);
});

it('rejects unknown channels', function () use ($valid) {
    $this->postJson('/api/campaigns', $valid(['channels' => [['channel' => 'pigeon']]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('channels.0.channel');
});

it('launches a campaign once', function () {
    contactWithTags();
    contactWithTags();
    $campaign = Campaign::factory()->create();

    $this->postJson("/api/campaigns/{$campaign->id}/launch")
        ->assertOk()
        ->assertJsonPath('planned_recipients', 2)
        ->assertJsonPath('data.status', 'running');

    $this->postJson("/api/campaigns/{$campaign->id}/launch")->assertStatus(409);
});

it('cancels a running campaign', function () {
    $campaign = Campaign::factory()->running()->create();

    $this->postJson("/api/campaigns/{$campaign->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Cancelled);
});

it('returns 404 as JSON for unknown campaigns', function () {
    $this->getJson('/api/campaigns/999')->assertNotFound();
});
