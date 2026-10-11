<?php

namespace App\Messaging\Drivers;

/**
 * In-memory record of what the fake drivers "sent". Bound as a singleton so
 * tests can assert on it the same way they would assert on Mail::fake().
 */
class FakeOutbox
{
    /** @var list<array{message: OutboundMessage, receipt: SendReceipt}> */
    private array $sent = [];

    public function record(OutboundMessage $message, SendReceipt $receipt): void
    {
        $this->sent[] = ['message' => $message, 'receipt' => $receipt];
    }

    /** @return list<array{message: OutboundMessage, receipt: SendReceipt}> */
    public function all(): array
    {
        return $this->sent;
    }

    public function count(): int
    {
        return count($this->sent);
    }
}
