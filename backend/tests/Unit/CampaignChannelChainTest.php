<?php

use App\Enums\Channel;
use App\Models\Campaign;

$campaign = fn (array $chain) => new Campaign(['channels' => $chain]);

it('sends leading non-fallback channels immediately', function () use ($campaign) {
    $c = $campaign([
        ['channel' => 'whatsapp', 'fallback' => false],
        ['channel' => 'email', 'fallback' => false],
        ['channel' => 'sms', 'fallback' => true],
    ]);

    expect($c->primaryChannels())->toBe([Channel::WhatsApp, Channel::Email]);
});

it('stops the primary set at the first fallback entry', function () use ($campaign) {
    $c = $campaign([
        ['channel' => 'whatsapp', 'fallback' => false],
        ['channel' => 'sms', 'fallback' => true],
        ['channel' => 'email', 'fallback' => false],
    ]);

    expect($c->primaryChannels())->toBe([Channel::WhatsApp]);
});

it('finds the fallback that follows a failed channel', function () use ($campaign) {
    $c = $campaign([
        ['channel' => 'whatsapp', 'fallback' => false],
        ['channel' => 'telegram', 'fallback' => true],
        ['channel' => 'sms', 'fallback' => true],
    ]);

    expect($c->fallbackAfter(Channel::WhatsApp))->toBe(Channel::Telegram)
        ->and($c->fallbackAfter(Channel::Telegram))->toBe(Channel::Sms)
        ->and($c->fallbackAfter(Channel::Sms))->toBeNull()
        ->and($c->fallbackAfter(Channel::Email))->toBeNull();
});
