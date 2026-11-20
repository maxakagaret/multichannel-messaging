<?php

namespace App\Console\Commands;

use App\Enums\MessageStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention window for messages (their event timeline goes with them).
 *
 * Walks the table by primary key in batches (SELECT id ... LIMIT, then
 * DELETE ... WHERE id IN) instead of one "DELETE WHERE created_at < ?":
 * short transactions, no long locks, safe to stop at any point. Ids grow with
 * time, so the first batch without a single old row ends the scan.
 * Messages that are still pending or queued are kept whatever their age.
 */
class PruneMessages extends Command
{
    protected $signature = 'messaging:prune
        {--days= : Keep this many days (default: messaging.retention.days)}
        {--batch=1000 : Rows per batch}
        {--dry-run : Count only}';

    protected $description = 'Delete final messages older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('messaging.retention.days'));
        $batch = max(1, (int) $this->option('batch'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = CarbonImmutable::now()->subDays($days);
        $final = collect(MessageStatus::cases())->filter->isFinal()->map->value->values()->all();

        $cursor = 0;
        $deleted = 0;

        while (true) {
            $rows = DB::table('messages')->where('id', '>', $cursor)->orderBy('id')->limit($batch)
                ->get(['id', 'status', 'created_at']);

            $old = $rows->filter(fn ($row) => CarbonImmutable::parse($row->created_at)->lt($cutoff));

            if ($old->isEmpty()) {
                break;
            }

            $ids = $old->whereIn('status', $final)->pluck('id')->all();

            if ($ids && ! $dryRun) {
                DB::transaction(function () use ($ids) {
                    DB::table('message_events')->whereIn('message_id', $ids)->delete();
                    DB::table('messages')->whereIn('id', $ids)->delete();
                });
            }

            $deleted += count($ids);
            $cursor = $rows->last()->id;
        }

        $this->info(($dryRun ? 'Would delete' : 'Deleted')." {$deleted} message(s) older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
