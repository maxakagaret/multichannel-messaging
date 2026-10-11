<?php

use App\Models\Campaign;
use Database\Seeders\DemoSeeder;

it('seeds synthetic demo data and reports a delivery overview', function () {
    $this->seed(DemoSeeder::class);

    $response = $this->getJson('/api/stats/overview?hours=240')->assertOk();

    $whatsapp = collect($response->json('data.channels'))->firstWhere('channel', 'whatsapp');

    expect($response->json('data.contacts'))->toBe(800)
        ->and($whatsapp['attempted'])->toBeGreaterThan(100)
        ->and($whatsapp['delivery_rate'])->toBeGreaterThan(0.8)->toBeLessThan(1.0)
        ->and($whatsapp['avg_delivery_seconds'])->toBeGreaterThan(0)
        ->and($response->json('data.top_errors'))->not->toBeEmpty();
});

it('reports per-campaign funnel numbers', function () {
    $this->seed(DemoSeeder::class);
    $campaign = Campaign::where('name', 'Winter newsletter')->sole();

    $this->getJson("/api/campaigns/{$campaign->id}/stats")
        ->assertOk()
        ->assertJsonPath('data.campaign_id', $campaign->id)
        ->assertJsonCount(2, 'data.channels');

    $this->getJson("/api/campaigns/{$campaign->id}/messages?status=failed")
        ->assertOk()
        ->assertJsonPath('data.0.status', 'failed');
});

it('lists the sender pool and contact tags', function () {
    $this->seed(DemoSeeder::class);

    $this->getJson('/api/senders')->assertOk()->assertJsonCount(6, 'data');
    $this->getJson('/api/contacts/tags')->assertOk()->assertJsonFragment(['tag' => 'vip']);
});
