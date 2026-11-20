<?php

namespace App\Messaging\Senders;

use App\Messaging\Exceptions\TransientDeliveryException;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Per-sender circuit breaker for connection timeouts.
 *
 * During a provider outage every call to a dead account hangs for the full
 * connect timeout, and workers spend their time waiting instead of sending
 * through healthy accounts. After `failures` timeouts within `window_seconds`
 * the breaker opens: calls fail fast with the same kind of transient error
 * (so the job's retry and backoff apply unchanged) until `open_seconds` pass.
 * Any answer from the server, success or a non-timeout error, resets the count.
 */
class CircuitBreaker
{
    public function __construct(private readonly Repository $cache) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    public function call(string $key, Closure $call): mixed
    {
        $failures = (int) config('messaging.breaker.failures');

        if ($failures > 0 && $this->isOpen($key)) {
            throw new TransientDeliveryException('breaker_open', 'Skipped: recent connection timeouts for this sender.');
        }

        try {
            $result = $call();
        } catch (TransientDeliveryException $e) {
            $this->recordFailure($key, $e, $failures);

            throw $e;
        } catch (Throwable $e) {
            $this->cache->forget("breaker:failures:{$key}");

            throw $e;
        }

        $this->cache->forget("breaker:failures:{$key}");

        return $result;
    }

    public function isOpen(string $key): bool
    {
        return $this->cache->has("breaker:open:{$key}");
    }

    private function recordFailure(string $key, TransientDeliveryException $e, int $failures): void
    {
        $counter = "breaker:failures:{$key}";

        if ($failures <= 0 || ! in_array($e->errorCode, config('messaging.breaker.error_codes'), true)) {
            // The server answered: the connection itself is fine.
            $this->cache->forget($counter);

            return;
        }

        $this->cache->add($counter, 0, (int) config('messaging.breaker.window_seconds'));

        if ($this->cache->increment($counter) >= $failures) {
            $this->cache->put("breaker:open:{$key}", true, (int) config('messaging.breaker.open_seconds'));
            $this->cache->forget($counter);
        }
    }
}
