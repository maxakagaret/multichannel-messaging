<?php

namespace App\Messaging;

use App\Enums\Channel;
use App\Messaging\Contracts\ChannelDriver;
use App\Messaging\Drivers\FakeChannelDriver;
use App\Messaging\Drivers\FakeOutbox;
use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the driver configured for a channel (config/messaging.php → drivers).
 *
 * Real integrations plug in without touching the core:
 *
 *   app(ChannelManager::class)->extend('acme-sms', fn (Channel $c, Container $app) => new AcmeSmsDriver(...));
 *   // and MESSAGING_SMS_DRIVER=acme-sms
 */
class ChannelManager
{
    /** @var array<string, Closure(Channel, Container): ChannelDriver> */
    private array $creators = [];

    /** @var array<string, ChannelDriver> */
    private array $resolved = [];

    public function __construct(private readonly Container $app) {}

    public function extend(string $driver, Closure $creator): void
    {
        $this->creators[$driver] = $creator;
        $this->resolved = [];
    }

    public function channel(Channel $channel): ChannelDriver
    {
        return $this->resolved[$channel->value] ??= $this->create($channel);
    }

    private function create(Channel $channel): ChannelDriver
    {
        $driver = config("messaging.drivers.{$channel->value}");

        if (isset($this->creators[$driver])) {
            return ($this->creators[$driver])($channel, $this->app);
        }

        if ($driver === 'fake') {
            return new FakeChannelDriver($channel, $this->app->make(FakeOutbox::class), config('messaging.fake'));
        }

        throw new InvalidArgumentException("Messaging driver [{$driver}] for channel [{$channel->value}] is not registered.");
    }
}
