<?php

use App\Messaging\Planning\AudienceResolver;
use App\Models\Campaign;
use App\Models\Contact;

it('selects consented contacts matching any audience tag', function () {
    $vip = contactWithTags(['vip']);
    $lapsed = contactWithTags(['lapsed', 'weekend']);
    contactWithTags(['newsletter']);
    $optedOut = contactWithTags(['vip'], ['marketing_consent' => false]);

    $campaign = Campaign::factory()->create(['audience' => ['tags' => ['vip', 'lapsed']]]);

    expect(app(AudienceResolver::class)->contactIds($campaign))->toBe([$vip->id, $lapsed->id])
        ->not->toContain($optedOut->id);
});

it('selects everybody with consent when no tags are given', function () {
    Contact::factory()->consented()->count(3)->create();
    Contact::factory()->count(2)->create(['marketing_consent' => false]);

    $campaign = Campaign::factory()->create(['audience' => ['tags' => []]]);

    expect(app(AudienceResolver::class)->contactIds($campaign))->toHaveCount(3);
});

it('skips contacts unreachable in every primary channel', function () {
    $withPhone = contactWithTags();
    contactWithTags([], ['phone' => null]);

    // SMS is only a fallback here, so a phone is the only thing that matters.
    $campaign = Campaign::factory()->channels([
        ['channel' => 'whatsapp'], ['channel' => 'sms', 'fallback' => true],
    ])->create();

    expect(app(AudienceResolver::class)->contactIds($campaign))->toBe([$withPhone->id]);
});
