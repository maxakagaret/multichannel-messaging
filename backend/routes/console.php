<?php

use Illuminate\Support\Facades\Schedule;

// Every minute: planned recipients whose day has come → queued messages.
// withoutOverlapping: a slow run must not be picked up twice by the next tick.
Schedule::command('messaging:dispatch-due')->everyMinute()->withoutOverlapping();
