<?php

use App\Enums\Channel;
use App\Enums\MessageStatus;
use App\Jobs\SendMessage;
use App\Messaging\Delivery\MessageFactory;
use App\Messaging\Delivery\SenderPool;
use App\Messaging\Delivery\StatusRecorder;
use App\Messaging\Drivers\FakeOutbox;
use App\Messaging\Exceptions\NoSenderAvailableException;
use App\Messaging\Exceptions\SenderRestrictedException;
use App\Messaging\Senders\SenderQuarantine;
use App\Models\Campaign;
use App\Models\Message;
use App\Models\Sender;
use App\Models\SenderQuarantine as QuarantineRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'messaging.quarantine' => ['threshold' => 3, 'window_hours' => 24, 'burst_seconds' => 60, 'duration_hours' => 48],
        'messaging.warmup.profiles.after_quarantine' => [['silent' => true], ['limit' => 5, 'pace' => 1]],
    ]);
    $this->now = CarbonImmutable::now();
    $this->quarantine = app(SenderQuarantine::class);
    $this->sender = Sender::factory()->channel(Channel::WhatsApp)->create(['name' => 'WA 1', 'account_id' => '+15550009999']);
});

it('ignores a single restriction answer', function () {
    expect($this->quarantine->recordRestriction($this->sender, 'sender_restricted', $this->now))->toBeFalse()
        ->and($this->sender->refresh()->quarantined_until)->toBeNull();
});

it('quarantines after a series and restarts the warm-up when it ends', function () {
    $this->quarantine->recordRestriction($this->sender, 'sender_restricted', $this->now->subHours(4));
    $this->quarantine->recordRestriction($this->sender, 'sender_restricted', $this->now->subHours(2));
    $quarantined = $this->quarantine->recordRestriction($this->sender, 'sender_restricted', $this->now);

    $sender = $this->sender->refresh();
    $record = QuarantineRecord::sole();
    expect($quarantined)->toBeTrue()
        ->and($sender->quarantined_until->toDateTimeString())->toBe('2026-03-04 12:00:00')
        ->and($sender->warmup_profile)->toBe(Sender::WARMUP_AFTER_QUARANTINE)
        ->and($sender->warmup_started_at->eq($sender->quarantined_until))->toBeTrue()
        ->and($record->signals)->toBe(3)
        ->and($record->account_id)->toBe('+15550009999');
});

it('counts a burst of answers from parallel workers as one signal', function () {
    foreach (range(0, 4) as $second) {
        $this->quarantine->recordRestriction($this->sender, 'sender_restricted', $this->now->addSeconds($second));
    }

    expect($this->sender->refresh()->quarantined_until)->toBeNull()
        ->and($this->quarantine->signals($this->sender, $this->now->addSeconds(5)))->toBe(1);
});

it('forgets signals outside the window', function () {
    $this->quarantine->recordRestriction($this->sender, 'sender_restricted', $this->now->subHours(30));
    $this->quarantine->recordRestriction($this->sender, 'sender_restricted', $this->now->subHours(26));
    $this->quarantine->recordRestriction($this->sender, 'sender_restricted', $this->now);

    expect($this->sender->refresh()->quarantined_until)->toBeNull();
});

it('gives no slots to a quarantined sender, then warms it up from silence', function () {
    config(['messaging.throttle_seconds.whatsapp' => 20]);
    $this->sender->forceFill(['quarantined_until' => $this->now->addHours(48)])
        ->startWarmup(Sender::WARMUP_AFTER_QUARANTINE, $this->now->addHours(48))->save();
    $pool = app(SenderPool::class);

    expect(fn () => $pool->reserve(Channel::WhatsApp, $this->now))->toThrow(NoSenderAvailableException::class);

    $this->travel(49)->hours();
    $slot = $pool->reserve(Channel::WhatsApp, CarbonImmutable::now());

    // Quarantine ended 2026-03-04 12:00; day 1 of the warm-up is silent.
    expect($slot->sendAt->toDateTimeString())->toBe('2026-03-05 12:00:00');
});

describe('send job', function () {
    beforeEach(function () {
        Queue::fake();
        config(['messaging.fake.restricted_senders' => ['WA 1']]);
        Sender::factory()->channel(Channel::Sms)->create();
        $this->campaign = Campaign::factory()->channels([['channel' => 'whatsapp'], ['channel' => 'sms', 'fallback' => true]])->create();
    });

    function queuedOn(Sender $sender, Campaign $campaign): Message
    {
        [$message] = app(MessageFactory::class)->forCampaign($campaign, contactWithTags(), Channel::WhatsApp);
        app(StatusRecorder::class)->transition($message, MessageStatus::Queued, 'test', ['sender_id' => $sender->id]);

        return $message->refresh();
    }

    it('retries a restricted send and falls back once the sender is quarantined', function () {
        $message = queuedOn($this->sender, $this->campaign);
        $run = fn () => app()->call([new SendMessage($message->id), 'handle']);

        expect($run)->toThrow(SenderRestrictedException::class);
        $this->travel(2)->minutes();
        expect($run)->toThrow(SenderRestrictedException::class);
        $this->travel(2)->minutes();
        $run(); // third signal: quarantine, no rethrow

        expect($message->refresh()->status)->toBe(MessageStatus::Failed)
            ->and($message->error_code)->toBe('sender_quarantined')
            ->and(Message::where('fallback_of_id', $message->id)->sole()->channel)->toBe(Channel::Sms);
    });

    it('fails queued messages of a quarantined sender without calling the provider', function () {
        $this->sender->forceFill(['quarantined_until' => $this->now->addDay()])->save();
        $message = queuedOn($this->sender, $this->campaign);

        app()->call([new SendMessage($message->id), 'handle']);

        expect($message->refresh()->status)->toBe(MessageStatus::Failed)
            ->and($message->error_code)->toBe('sender_quarantined')
            ->and($message->attempts)->toBe(0)
            ->and(app(FakeOutbox::class)->count())->toBe(0);
    });
});
