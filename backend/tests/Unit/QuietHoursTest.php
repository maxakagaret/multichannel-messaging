<?php

use App\Messaging\Delivery\QuietHours;
use Carbon\CarbonImmutable;

$window = fn () => new QuietHours('Europe/Berlin', '09:00', '21:00');

it('keeps a moment that is inside the window', function () use ($window) {
    $moment = CarbonImmutable::parse('2026-03-02 10:00', 'UTC'); // 11:00 in Berlin

    expect($window()->nextAllowed($moment)->toIso8601String())->toBe($moment->toIso8601String());
});

it('moves an early moment to the opening of the same local day', function () use ($window) {
    $moment = CarbonImmutable::parse('2026-03-02 05:00', 'UTC'); // 06:00 in Berlin

    expect($window()->nextAllowed($moment)->toDateTimeString())->toBe('2026-03-02 08:00:00');
});

it('moves a late moment to the opening of the next local day', function () use ($window) {
    $moment = CarbonImmutable::parse('2026-03-02 20:30', 'UTC'); // 21:30 in Berlin

    expect($window()->nextAllowed($moment)->toDateTimeString())->toBe('2026-03-03 08:00:00');
});

it('always returns UTC', function () use ($window) {
    expect($window()->nextAllowed(CarbonImmutable::parse('2026-03-02 05:00', 'UTC'))->getTimezone()->getName())
        ->toBe('UTC');
});
