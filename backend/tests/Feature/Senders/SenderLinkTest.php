<?php

use App\Enums\Channel;
use App\Models\Sender;
use Carbon\CarbonImmutable;

$credentials = fn (string $account = '+15550004242') => ['credentials' => ['account' => $account, 'keys' => ['noise' => 'k1', 'identity' => 'k2']]];

beforeEach(function () {
    $this->sender = Sender::factory()->channel(Channel::WhatsApp)->create(['is_active' => false]);
});

function issueToken(Sender $sender): string
{
    return test()->postJson("/api/senders/{$sender->id}/link-token")->assertCreated()->json('data.token');
}

it('links an account with a one-time token and starts the warm-up', function () use ($credentials) {
    $token = issueToken($this->sender);

    $this->postJson("/api/senders/link/{$token}", $credentials())
        ->assertOk()
        ->assertJsonPath('data.account_id', '+15550004242');

    $sender = $this->sender->refresh();
    expect($sender->linked_at)->not->toBeNull()
        ->and($sender->is_active)->toBeTrue()
        ->and($sender->warmup_profile)->toBe(Sender::WARMUP_NEW)
        ->and($sender->link_token_hash)->toBeNull();
});

it('stores only a hash of the token', function () {
    $token = issueToken($this->sender);

    expect($this->sender->refresh()->link_token_hash)->toBe(hash('sha256', $token))
        ->and($this->getJson('/api/senders')->json('data.0'))->not->toHaveKey('link_token_hash');
});

it('accepts a token once', function () use ($credentials) {
    $token = issueToken($this->sender);
    $this->postJson("/api/senders/link/{$token}", $credentials())->assertOk();
    $this->deleteJson("/api/senders/{$this->sender->id}/link")->assertOk();

    $this->postJson("/api/senders/link/{$token}", $credentials())
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'invalid_token');
});

it('rejects an expired token', function () use ($credentials) {
    $token = issueToken($this->sender);
    $this->travel(16)->minutes();

    $this->postJson("/api/senders/link/{$token}", $credentials())->assertJsonPath('reason', 'invalid_token');
});

it('invalidates the previous token when a new one is issued', function () use ($credentials) {
    $old = issueToken($this->sender);
    $new = issueToken($this->sender);

    $this->postJson("/api/senders/link/{$old}", $credentials())->assertJsonPath('reason', 'invalid_token');
    $this->postJson("/api/senders/link/{$new}", $credentials())->assertOk();
});

it('refuses a sender that is already linked or quarantined, and burns the token', function () use ($credentials) {
    $this->sender->forceFill(['linked_at' => now()])->save();
    $token = issueToken($this->sender);
    $this->postJson("/api/senders/link/{$token}", $credentials())->assertJsonPath('reason', 'already_linked');

    $this->sender->forceFill(['linked_at' => null, 'quarantined_until' => now()->addDay()])->save();
    $token = issueToken($this->sender);
    $this->postJson("/api/senders/link/{$token}", $credentials())->assertJsonPath('reason', 'quarantined');

    expect($this->sender->refresh()->link_token_hash)->toBeNull();
});

it('rejects credentials the provider does not accept', function () {
    $token = issueToken($this->sender);

    $this->postJson("/api/senders/link/{$token}", ['credentials' => ['account' => '+1555']])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'invalid_credentials');

    expect($this->sender->refresh()->linked_at)->toBeNull();
});

it('keeps the warm-up when the same account is linked again, restarts it for a new one', function () use ($credentials) {
    $this->postJson('/api/senders/link/'.issueToken($this->sender), $credentials('+15550000001'))->assertOk();
    $startedAt = $this->sender->refresh()->warmup_started_at;

    $this->travel(2)->days();
    $this->deleteJson("/api/senders/{$this->sender->id}/link")->assertOk();
    $this->postJson('/api/senders/link/'.issueToken($this->sender), $credentials('+15550000001'))->assertOk();
    expect($this->sender->refresh()->warmup_started_at->eq($startedAt))->toBeTrue();

    $this->deleteJson("/api/senders/{$this->sender->id}/link")->assertOk();
    $this->postJson('/api/senders/link/'.issueToken($this->sender), $credentials('+15550000002'))->assertOk();
    expect($this->sender->refresh()->warmup_started_at->eq(CarbonImmutable::now()))->toBeTrue();
});
