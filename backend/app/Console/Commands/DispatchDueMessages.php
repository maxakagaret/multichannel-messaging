<?php

namespace App\Console\Commands;

use App\Messaging\Planning\DueRecipientsDispatcher;
use Illuminate\Console\Command;

class DispatchDueMessages extends Command
{
    protected $signature = 'messaging:dispatch-due {--batch=200 : Recipients per campaign per run}';

    protected $description = 'Turn today\'s planned campaign recipients into queued messages';

    public function handle(DueRecipientsDispatcher $dispatcher): int
    {
        $queued = $dispatcher->dispatch(batchSize: (int) $this->option('batch'));

        $this->info("Queued {$queued} message(s).");

        return self::SUCCESS;
    }
}
