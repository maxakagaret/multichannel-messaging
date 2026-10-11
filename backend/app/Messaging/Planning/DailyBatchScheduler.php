<?php

namespace App\Messaging\Planning;

use App\Models\Campaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Spreads a campaign's audience over calendar days so that no day exceeds the
 * campaign's daily cap. A provider seeing 5 000 identical messages from a fresh
 * number in one hour bans it; 50 a day for 100 days goes through.
 *
 * Already planned but unprocessed recipients count towards each day's load, so
 * adding contacts to a running campaign keeps the cap intact.
 */
class DailyBatchScheduler
{
    /**
     * @param  list<int>  $contactIds
     * @return int number of newly planned recipients
     */
    public function spread(Campaign $campaign, array $contactIds, CarbonImmutable $startDate): int
    {
        $cap = max(1, (int) $campaign->daily_cap);

        $alreadyPlanned = DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->pluck('contact_id')
            ->flip();

        $load = DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->whereNull('processed_at')
            ->where('send_on', '>=', $startDate->toDateString())
            ->selectRaw('send_on, count(*) as total')
            ->groupBy('send_on')
            ->pluck('total', 'send_on')
            ->mapWithKeys(fn ($total, $day) => [substr((string) $day, 0, 10) => (int) $total])
            ->all();

        $day = $startDate->startOfDay();
        $rows = [];

        foreach ($contactIds as $contactId) {
            if ($alreadyPlanned->has($contactId)) {
                continue;
            }

            while (($load[$day->toDateString()] ?? 0) >= $cap) {
                $day = $day->addDay();
            }

            $rows[] = ['campaign_id' => $campaign->id, 'contact_id' => $contactId, 'send_on' => $day->toDateString()];
            $load[$day->toDateString()] = ($load[$day->toDateString()] ?? 0) + 1;
        }

        $inserted = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $inserted += DB::table('campaign_recipients')->insertOrIgnore($chunk);
        }

        return $inserted;
    }
}
