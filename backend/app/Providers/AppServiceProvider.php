<?php

namespace App\Providers;

use App\Jobs\SendMessage;
use App\Messaging\ChannelManager;
use App\Messaging\Delivery\QuietHours;
use App\Messaging\Drivers\FakeOutbox;
use App\Models\Message;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelManager::class);
        $this->app->singleton(FakeOutbox::class);
        $this->app->bind(QuietHours::class, fn () => QuietHours::fromConfig());
    }

    public function boot(): void
    {
        // Per-sender ceiling used by the SendMessage job middleware.
        RateLimiter::for('messaging-sender', function (SendMessage $job) {
            $message = Message::find($job->messageId);

            if (! $message) {
                return Limit::none();
            }

            $perMinute = (int) config("messaging.rate_limit_per_minute.{$message->channel->value}", 60);
            $key = $message->sender_id ? "sender:{$message->sender_id}" : "channel:{$message->channel->value}";

            return Limit::perMinute($perMinute)->by($key);
        });
    }
}
