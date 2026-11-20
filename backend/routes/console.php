<?php

use Illuminate\Support\Facades\Schedule;

// Every minute: planned recipients whose day has come → queued messages.
// withoutOverlapping: a slow run must not be picked up twice by the next tick.
Schedule::command('messaging:dispatch-due')->everyMinute()->withoutOverlapping();

// Nightly retention window, behind a flag: the first run on a big table is a
// planned operation (backup first), not something a deploy should start.
Schedule::command('messaging:prune')->dailyAt('04:10')->withoutOverlapping()
    ->when(fn () => (bool) config('messaging.retention.enabled'));
