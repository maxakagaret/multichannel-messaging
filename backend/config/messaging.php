<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Channel drivers
    |--------------------------------------------------------------------------
    | Every channel is resolved through App\Messaging\ChannelManager. The demo
    | ships only fake drivers: they never leave the process, but they behave
    | like real providers (accept / transient error / permanent error) based on
    | a deterministic hash of the recipient, so tests and demos are repeatable.
    */
    'drivers' => [
        'sms'      => env('MESSAGING_SMS_DRIVER', 'fake'),
        'email'    => env('MESSAGING_EMAIL_DRIVER', 'fake'),
        'whatsapp' => env('MESSAGING_WHATSAPP_DRIVER', 'fake'),
        'telegram' => env('MESSAGING_TELEGRAM_DRIVER', 'fake'),
    ],

    'fake' => [
        // Share of recipients (0..100) that get a permanent / transient error.
        'permanent_failure_percent' => (int) env('FAKE_PERMANENT_FAILURE_PERCENT', 8),
        'transient_failure_percent' => (int) env('FAKE_TRANSIENT_FAILURE_PERCENT', 5),
        // Emit delivery receipts after a short delay, as a real provider would.
        'simulate_receipts' => (bool) env('FAKE_SIMULATE_RECEIPTS', true),
        'receipt_delay_seconds' => (int) env('FAKE_RECEIPT_DELAY_SECONDS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pacing
    |--------------------------------------------------------------------------
    | daily_cap_default — max recipients per campaign per calendar day.
    | throttle_seconds  — min gap between two messages from the same sender
    |                     (messenger channels get banned for bursts); a random
    |                     jitter is added so traffic does not look robotic.
    */
    'daily_cap_default' => (int) env('MESSAGING_DAILY_CAP', 50),

    'throttle_seconds' => [
        'sms'      => 0,
        'email'    => 0,
        'whatsapp' => 20,
        'telegram' => 3,
    ],

    'throttle_jitter_seconds' => 10,

    // Per-sender hard ceiling, enforced by the job middleware as a safety net.
    'rate_limit_per_minute' => [
        'sms'      => 600,
        'email'    => 600,
        'whatsapp' => 4,
        'telegram' => 30,
    ],

    // Local time window when promotional messages may be sent.
    'quiet_hours' => [
        'timezone' => env('MESSAGING_TIMEZONE', 'UTC'),
        'start'    => env('MESSAGING_SEND_FROM', '09:00'),
        'end'      => env('MESSAGING_SEND_UNTIL', '21:00'),
    ],

    'queue' => env('MESSAGING_QUEUE', 'messaging'),

    // Shared secret for HMAC-signed provider webhooks (delivery receipts).
    'webhook_secret' => env('MESSAGING_WEBHOOK_SECRET', 'local-dev-secret'),
];
