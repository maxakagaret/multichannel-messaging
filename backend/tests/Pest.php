<?php

use App\Enums\Channel;
use App\Messaging\Drivers\FakeChannelDriver;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/**
 * Finds a phone number whose fake-driver bucket satisfies $predicate, so tests
 * can pick "a recipient that always fails" without hardcoding hash values.
 */
function phoneInBucket(Channel $channel, callable $predicate): string
{
    for ($i = 0; $i < 10_000; $i++) {
        $phone = '+1555'.str_pad((string) $i, 7, '0', STR_PAD_LEFT);
        if ($predicate(FakeChannelDriver::bucket($channel, $phone), $phone)) {
            return $phone;
        }
    }

    throw new RuntimeException('No phone found for the requested bucket.');
}

function contactWithTags(array $tags = [], array $attributes = []): Contact
{
    $contact = Contact::factory()->consented()->create($attributes);
    $contact->syncTags($tags);

    return $contact;
}
