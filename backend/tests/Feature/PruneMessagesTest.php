<?php

use App\Enums\Channel;
use App\Enums\MessageStatus;
use App\Messaging\Delivery\MessageFactory;
use App\Models\Campaign;
use App\Models\Message;
use App\Models\MessageEvent;

function messageAged(int $days, MessageStatus $status): Message
{
    test()->travelTo(now()->subDays($days));
    $campaign = Campaign::factory()->channels([['channel' => 'email']])->create();
    [$message] = app(MessageFactory::class)->forCampaign($campaign, contactWithTags([], ['email' => fake()->unique()->safeEmail()]), Channel::Email);
    $message->forceFill(['status' => $status])->save();
    test()->travelTo('2026-03-02 12:00:00');

    return $message;
}

it('deletes final messages older than the window together with their events', function () {
    $old = messageAged(200, MessageStatus::Delivered);
    $oldPending = messageAged(200, MessageStatus::Queued);
    $recent = messageAged(10, MessageStatus::Read);

    $this->artisan('messaging:prune', ['--days' => 180, '--batch' => 1])
        ->expectsOutputToContain('Deleted 1 message(s)')
        ->assertSuccessful();

    expect(Message::pluck('id')->all())->toEqualCanonicalizing([$oldPending->id, $recent->id])
        ->and(MessageEvent::where('message_id', $old->id)->exists())->toBeFalse();
});

it('only counts on a dry run', function () {
    messageAged(200, MessageStatus::Failed);

    $this->artisan('messaging:prune', ['--days' => 180, '--dry-run' => true])
        ->expectsOutputToContain('Would delete 1 message(s)')
        ->assertSuccessful();

    expect(Message::count())->toBe(1);
});
