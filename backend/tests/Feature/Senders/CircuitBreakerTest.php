<?php

use App\Messaging\Exceptions\TransientDeliveryException;
use App\Messaging\Senders\CircuitBreaker;

beforeEach(function () {
    config(['messaging.breaker' => ['failures' => 3, 'window_seconds' => 60, 'open_seconds' => 60, 'error_codes' => ['connection_timeout']]]);
    $this->breaker = app(CircuitBreaker::class);
    $this->calls = 0;
    $this->timeout = function () {
        $this->calls++;
        throw new TransientDeliveryException('connection_timeout');
    };
});

function attempt(CircuitBreaker $breaker, string $key, Closure $call): ?string
{
    try {
        $breaker->call($key, $call);
    } catch (TransientDeliveryException $e) {
        return $e->errorCode;
    }

    return null;
}

it('stops calling a sender after repeated connection timeouts', function () {
    $codes = collect(range(1, 5))->map(fn () => attempt($this->breaker, 'sender:1', $this->timeout));

    expect($codes->all())->toBe(['connection_timeout', 'connection_timeout', 'connection_timeout', 'breaker_open', 'breaker_open'])
        ->and($this->calls)->toBe(3)
        // Another sender on the same provider is not affected.
        ->and(attempt($this->breaker, 'sender:2', fn () => 'ok'))->toBeNull();
});

it('lets calls through again once the open period is over', function () {
    collect(range(1, 3))->each(fn () => attempt($this->breaker, 'sender:1', $this->timeout));
    $this->travel(61)->seconds();

    expect(attempt($this->breaker, 'sender:1', fn () => 'ok'))->toBeNull();
});

it('resets the count on any answer from the server', function () {
    attempt($this->breaker, 'sender:1', $this->timeout);
    attempt($this->breaker, 'sender:1', $this->timeout);
    attempt($this->breaker, 'sender:1', fn () => throw new TransientDeliveryException('provider_busy'));
    attempt($this->breaker, 'sender:1', $this->timeout);

    expect($this->breaker->isOpen('sender:1'))->toBeFalse();
});
