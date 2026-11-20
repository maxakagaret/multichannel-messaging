<?php

use App\Enums\Channel;
use App\Enums\MessageStatus;
use App\Enums\Priority;
use App\Jobs\SendMessage;
use App\Messaging\Delivery\MessageDispatcher;
use App\Messaging\Delivery\MessageFactory;
use App\Messaging\Delivery\SenderPool;
use App\Messaging\Delivery\SlotRequest;
use App\Messaging\Drivers\FakeOutbox;
use App\Messaging\Exceptions\SlotPastDeadlineException;
use App\Models\Campaign;
use App\Models\Message;
use App\Models\Sender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'messaging.throttle_seconds.whatsapp' => 20,
        'messaging.warmup.urgent_reserve' => 0.2,
        'messaging.warmup.profiles.new' => [
            ['limit' => 5, 'pace' => 1],
            ['limit' => 10, 'pace' => 3],
        ],
        'messaging.warmup.profiles.after_quarantine' => [
            ['silent' => true],
            ['limit' => 5, 'pace' => 1],
        ],
    ]);
    $this->now = CarbonImmutable::now(); // 2026-03-02 12:00 UTC
    $this->pool = app(SenderPool::class);
});

function warmingSender(string $profile, CarbonImmutable $startedAt, array $attributes = []): Sender
{
    $sender = Sender::factory()->channel(Channel::WhatsApp)->create($attributes);
    $sender->startWarmup($profile, $startedAt)->save();

    return $sender;
}

it('keeps bulk under the daily limit minus the urgent reserve', function () {
    $sender = warmingSender(Sender::WARMUP_NEW, $this->now->subHour());

    $slots = collect(range(1, 5))->map(fn () => $this->pool->reserve(Channel::WhatsApp, $this->now));

    // limit 5, reserve 20 % → 4 bulk places today; the 5th waits for warm-up day 2.
    expect($slots->take(4)->every(fn ($s) => $s->sendAt->isSameDay($this->now)))->toBeTrue()
        ->and($slots[4]->sendAt->toDateTimeString())->toBe('2026-03-03 11:00:00')
        ->and($slots[4]->deferredBy)->toBe('daily warm-up limit');
});

it('lets urgent messages use the reserved part of the limit', function () {
    warmingSender(Sender::WARMUP_NEW, $this->now->subHour());
    $urgent = new SlotRequest(Priority::Urgent);

    collect(range(1, 4))->each(fn () => $this->pool->reserve(Channel::WhatsApp, $this->now));
    $fifth = $this->pool->reserve(Channel::WhatsApp, $this->now, $urgent);
    $sixth = $this->pool->reserve(Channel::WhatsApp, $this->now, $urgent);

    expect($fifth->sendAt->isSameDay($this->now))->toBeTrue()
        ->and($sixth->sendAt->toDateTimeString())->toBe('2026-03-03 11:00:00');
});

it('counts warm-up days from the warm-up start, not by calendar date', function () {
    // Linked yesterday at 23:00: at noon today it is still warm-up day 1.
    $sender = warmingSender(Sender::WARMUP_NEW, CarbonImmutable::parse('2026-03-01 23:00'));

    $slot = $this->pool->reserve(Channel::WhatsApp, $this->now);

    expect($slot->warmupDay->index)->toBe(0)
        ->and($slot->warmupDay->endsAt->toDateTimeString())->toBe('2026-03-02 23:00:00');
});

it('multiplies the throttle by the pace of the warm-up day', function () {
    warmingSender(Sender::WARMUP_NEW, $this->now->subDay()); // day 2: pace 3

    $times = collect(range(1, 3))->map(fn () => $this->now->diffInSeconds($this->pool->reserve(Channel::WhatsApp, $this->now)->sendAt));

    expect($times->all())->toEqual([0, 60, 120]);
});

it('prefers a sender with capacity over one whose warm-up day is full', function () {
    $full = warmingSender(Sender::WARMUP_NEW, $this->now->subHour(), ['name' => 'warming']);
    DB::table('sender_daily_usage')->insert(['sender_id' => $full->id, 'day_starts_at' => $this->now->subHour()->toDateTimeString(), 'reserved' => 5]);
    Sender::factory()->channel(Channel::WhatsApp)->create(['name' => 'warm', 'last_used_at' => $this->now]);

    $slot = $this->pool->reserve(Channel::WhatsApp, $this->now);

    expect($slot->sender->name)->toBe('warm')
        ->and($this->now->diffInSeconds($slot->sendAt))->toEqual(20);
});

it('sends nothing on a silent day, urgent and deadline-bound included', function () {
    warmingSender(Sender::WARMUP_AFTER_QUARANTINE, $this->now->subHours(2));

    $bulk = $this->pool->reserve(Channel::WhatsApp, $this->now);
    $urgent = $this->pool->reserve(Channel::WhatsApp, $this->now, new SlotRequest(Priority::Urgent, $this->now->addDays(3)));

    expect($bulk->sendAt->toDateTimeString())->toBe('2026-03-03 10:00:00')
        ->and($urgent->sendAt->toDateTimeString())->toBe('2026-03-03 10:00:00')
        ->and($bulk->deferredBy)->toBe('silent warm-up day');
});

it('does not hold deadline-bound messages behind the daily limit or the bulk tail', function () {
    $sender = warmingSender(Sender::WARMUP_NEW, $this->now->subHour(), ['last_used_at' => $this->now->addHours(5)]);
    DB::table('sender_daily_usage')->insert(['sender_id' => $sender->id, 'day_starts_at' => $this->now->subHour()->toDateTimeString(), 'reserved' => 5]);

    $reminder = $this->pool->reserve(Channel::WhatsApp, $this->now, new SlotRequest(Priority::Urgent, $this->now->addHours(2)));

    expect($reminder->sendAt->eq($this->now))->toBeTrue()
        ->and($sender->refresh()->priority_last_used_at->eq($this->now))->toBeTrue()
        ->and($sender->last_used_at->eq($this->now->addHours(5)))->toBeTrue();
});

it('refuses a slot past the deadline and reserves nothing', function () {
    $sender = warmingSender(Sender::WARMUP_AFTER_QUARANTINE, $this->now->subHour());

    expect(fn () => $this->pool->reserve(Channel::WhatsApp, $this->now, new SlotRequest(Priority::Urgent, $this->now->addHours(3))))
        ->toThrow(SlotPastDeadlineException::class);

    expect($sender->refresh()->priority_last_used_at)->toBeNull()
        ->and(DB::table('sender_daily_usage')->count())->toBe(0);
});

it('fails an expired message at planning time and falls back right away', function () {
    Queue::fake();
    warmingSender(Sender::WARMUP_AFTER_QUARANTINE, $this->now->subHour());
    Sender::factory()->channel(Channel::Sms)->create();
    $campaign = Campaign::factory()->channels([['channel' => 'whatsapp'], ['channel' => 'sms', 'fallback' => true]])
        ->create(['priority' => Priority::Urgent, 'ttl_minutes' => 120]);
    [$message] = app(MessageFactory::class)->forCampaign($campaign, contactWithTags(), Channel::WhatsApp);

    app(MessageDispatcher::class)->enqueue($message);

    $fallback = Message::where('fallback_of_id', $message->id)->sole();
    expect($message->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->error_code)->toBe('expired')
        ->and($fallback->channel)->toBe(Channel::Sms)
        ->and($fallback->status)->toBe(MessageStatus::Queued)
        // The fallback carries the original deadline: the content is just as old.
        ->and($fallback->expires_at->eq($message->expires_at))->toBeTrue();
});

it('never sends a message after its deadline, whatever queued the job', function () {
    Queue::fake();
    Sender::factory()->channel(Channel::WhatsApp)->create();
    $campaign = Campaign::factory()->channels([['channel' => 'whatsapp']])->create(['ttl_minutes' => 30]);
    [$message] = app(MessageFactory::class)->forCampaign($campaign, contactWithTags(), Channel::WhatsApp);
    app(MessageDispatcher::class)->enqueue($message);

    $this->travel(31)->minutes();
    app()->call([new SendMessage($message->id), 'handle']);

    expect($message->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->error_code)->toBe('expired')
        ->and(app(FakeOutbox::class)->count())->toBe(0);
});
