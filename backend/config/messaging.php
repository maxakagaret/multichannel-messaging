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
        // Sender names whose account the fake provider reports as restricted:
        // every send from them gets "sender_restricted" (drives the quarantine demo).
        'restricted_senders' => array_values(array_filter(explode(',', (string) env('FAKE_RESTRICTED_SENDERS', '')))),
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

    /*
    |--------------------------------------------------------------------------
    | Sender warm-up
    |--------------------------------------------------------------------------
    | A freshly linked messenger account that starts sending at full speed gets
    | banned. While a sender warms up, every warm-up day (24 h counted from
    | warmup_started_at, not the calendar date) has:
    |   limit  — reservations per day (urgent may use all, bulk only limit minus the reserve);
    |   pace   — multiplier applied to the channel throttle;
    |   silent — no API activity at all, not even for urgent messages.
    | After the last day of the profile the sender is fully warm.
    |
    | Deadline-bound messages (campaign ttl) are not limited by the daily
    | limit or the pace: they either go out now or not at all. They still take
    | a place in the daily counter and respect silent days.
    */
    'warmup' => [
        'urgent_reserve' => (float) env('MESSAGING_WARMUP_URGENT_RESERVE', 0.2),
        'profiles' => [
            'new' => [
                ['limit' => 20, 'pace' => 4],
                ['limit' => 40, 'pace' => 3],
                ['limit' => 80, 'pace' => 2],
                ['limit' => 150, 'pace' => 1.5],
            ],
            // After a quarantine the provider asks for no activity for a while
            // and then a slow ramp: ~12 messages spread over the whole day.
            'after_quarantine' => [
                ['silent' => true],
                ['silent' => true],
                ['limit' => 12, 'pace' => 240],
                ['limit' => 25, 'pace' => 120],
                ['limit' => 50, 'pace' => 40],
                ['limit' => 100, 'pace' => 10],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sender quarantine
    |--------------------------------------------------------------------------
    | The provider answers "account restricted" (think HTTP 429 with a ban
    | flavour) when the messenger limits an account. A single answer happens to
    | healthy accounts too; a series means the account must go quiet. While
    | quarantined, the sender gets no slots, its queued messages fall back to
    | the next channel, and when the quarantine ends it warms up again with the
    | after_quarantine profile.
    |
    | burst_seconds — answers this close together are one signal: parallel
    | workers that passed the pre-check at the same moment all get their own
    | "restricted", and five of them in one second are one event, not five.
    */
    'quarantine' => [
        'threshold' => (int) env('MESSAGING_QUARANTINE_THRESHOLD', 3),
        'window_hours' => (int) env('MESSAGING_QUARANTINE_WINDOW_HOURS', 24),
        'burst_seconds' => (int) env('MESSAGING_QUARANTINE_BURST_SECONDS', 60),
        'duration_hours' => (int) env('MESSAGING_QUARANTINE_HOURS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    | After `failures` connection timeouts of one sender within `window_seconds`,
    | calls for that sender stop going to the network for `open_seconds` and fail
    | fast as transient errors. Keyed by sender, not by host: during a partial
    | provider outage some accounts on the same host answer and some do not.
    */
    'breaker' => [
        'failures' => (int) env('MESSAGING_BREAKER_FAILURES', 3),
        'window_seconds' => (int) env('MESSAGING_BREAKER_WINDOW_SECONDS', 60),
        'open_seconds' => (int) env('MESSAGING_BREAKER_OPEN_SECONDS', 60),
        'error_codes' => ['connection_timeout'],
    ],

    // One-time token for linking a provider account to a sender.
    'link_token_minutes' => (int) env('MESSAGING_LINK_TOKEN_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | messaging:prune deletes final messages older than `days` (events go with
    | them). Recent rows serve webhooks, duplicate checks and dashboards; the
    | long tail only grows the table.
    */
    'retention' => [
        'enabled' => (bool) env('MESSAGING_PRUNE_ENABLED', false),
        'days' => (int) env('MESSAGING_RETENTION_DAYS', 180),
    ],

    'queue' => env('MESSAGING_QUEUE', 'messaging'),

    // Shared secret for HMAC-signed provider webhooks (delivery receipts).
    'webhook_secret' => env('MESSAGING_WEBHOOK_SECRET', 'local-dev-secret'),
];
