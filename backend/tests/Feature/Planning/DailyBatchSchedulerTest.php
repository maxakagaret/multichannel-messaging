<?php

use App\Messaging\Planning\DailyBatchScheduler;
use App\Models\Campaign;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function perDay(Campaign $campaign): array
{
    return DB::table('campaign_recipients')->where('campaign_id', $campaign->id)
        ->selectRaw('send_on, count(*) as total')->groupBy('send_on')->orderBy('send_on')
        ->pluck('total', 'send_on')->map(fn ($t) => (int) $t)->all();
}

it('never plans more than the daily cap for one day', function () {
    $campaign = Campaign::factory()->create(['daily_cap' => 3]);
    $ids = Contact::factory()->count(8)->create()->pluck('id')->all();

    $planned = app(DailyBatchScheduler::class)->spread($campaign, $ids, CarbonImmutable::parse('2026-03-02'));

    expect($planned)->toBe(8)
        ->and(perDay($campaign))->toBe(['2026-03-02' => 3, '2026-03-03' => 3, '2026-03-04' => 2]);
});

it('counts already planned recipients towards the day load', function () {
    $campaign = Campaign::factory()->create(['daily_cap' => 3]);
    $first = Contact::factory()->count(2)->create()->pluck('id')->all();
    $second = Contact::factory()->count(3)->create()->pluck('id')->all();
    $scheduler = app(DailyBatchScheduler::class);

    $scheduler->spread($campaign, $first, CarbonImmutable::parse('2026-03-02'));
    $scheduler->spread($campaign, $second, CarbonImmutable::parse('2026-03-02'));

    expect(perDay($campaign))->toBe(['2026-03-02' => 3, '2026-03-03' => 2]);
});

it('does not plan the same contact twice', function () {
    $campaign = Campaign::factory()->create(['daily_cap' => 10]);
    $ids = Contact::factory()->count(4)->create()->pluck('id')->all();
    $scheduler = app(DailyBatchScheduler::class);

    $scheduler->spread($campaign, $ids, CarbonImmutable::parse('2026-03-02'));
    $again = $scheduler->spread($campaign, $ids, CarbonImmutable::parse('2026-03-02'));

    expect($again)->toBe(0)
        ->and(DB::table('campaign_recipients')->count())->toBe(4);
});
