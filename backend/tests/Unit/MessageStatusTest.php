<?php

use App\Enums\MessageStatus;

it('only moves forward through the lifecycle', function (MessageStatus $from, MessageStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'pending → queued' => [MessageStatus::Pending, MessageStatus::Queued, true],
    'queued → sent' => [MessageStatus::Queued, MessageStatus::Sent, true],
    'sent → delivered' => [MessageStatus::Sent, MessageStatus::Delivered, true],
    'delivered → read' => [MessageStatus::Delivered, MessageStatus::Read, true],
    'late "sent" after delivered is ignored' => [MessageStatus::Delivered, MessageStatus::Sent, false],
    'read is never downgraded to delivered' => [MessageStatus::Read, MessageStatus::Delivered, false],
    'delivered cannot become failed' => [MessageStatus::Delivered, MessageStatus::Failed, false],
    'failed can still be delivered (provider-side retry)' => [MessageStatus::Failed, MessageStatus::Delivered, true],
    'queued can be skipped (campaign cancelled)' => [MessageStatus::Queued, MessageStatus::Skipped, true],
    'sent cannot be skipped' => [MessageStatus::Sent, MessageStatus::Skipped, false],
    'skipped is terminal' => [MessageStatus::Skipped, MessageStatus::Queued, false],
]);
