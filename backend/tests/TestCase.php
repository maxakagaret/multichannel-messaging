<?php

namespace Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic world: fixed "now" inside the sending window, no random
        // jitter, no asynchronous receipts unless a test opts in.
        $this->travelTo(CarbonImmutable::parse('2026-03-02 12:00:00', 'UTC'));

        config([
            'messaging.throttle_jitter_seconds' => 0,
            'messaging.fake.simulate_receipts' => false,
            'messaging.fake.permanent_failure_percent' => 0,
            'messaging.fake.transient_failure_percent' => 0,
            'messaging.quiet_hours' => ['timezone' => 'UTC', 'start' => '09:00', 'end' => '21:00'],
        ]);
    }
}
