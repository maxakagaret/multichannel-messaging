<?php

namespace App\Messaging\Senders;

use App\Messaging\ChannelManager;
use App\Messaging\Contracts\LinksAccounts;
use App\Messaging\Exceptions\SenderLinkException;
use App\Models\Sender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Links a provider account to a sender through a one-time token.
 *
 * The operator issues a token for a sender; the tool that holds the account's
 * credentials (a browser extension, a CLI on the owner's machine) uploads them
 * with that token. The credentials go straight to the provider and are never
 * stored or logged here.
 *
 *  - Only a hash of the token is stored; a new token replaces the previous one.
 *  - Redeeming burns the token first, under a row lock, whatever happens next:
 *    one token, one attempt, and two parallel uploads cannot both pass.
 *  - Re-linking the same account keeps its warm-up; a different account is a
 *    new number for the messenger and starts the warm-up from day one.
 */
class SenderLinker
{
    public function __construct(private readonly ChannelManager $channels) {}

    /** @return array{token: string, expires_at: CarbonImmutable} */
    public function issue(Sender $sender, CarbonImmutable $now): array
    {
        $token = Str::random(48);
        $expiresAt = $now->addMinutes((int) config('messaging.link_token_minutes'));

        $sender->forceFill([
            'link_token_hash' => hash('sha256', $token),
            'link_token_expires_at' => $expiresAt,
        ])->save();

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /** @throws SenderLinkException */
    public function redeem(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] array $credentials,
        CarbonImmutable $now,
    ): Sender {
        [$sender, $refusal] = DB::transaction(function () use ($token, $now) {
            $sender = Sender::where('link_token_hash', hash('sha256', $token))->lockForUpdate()->first();

            if (! $sender) {
                return [null, 'invalid_token'];
            }

            $expired = $sender->link_token_expires_at?->lte($now) ?? true;
            $sender->forceFill(['link_token_hash' => null, 'link_token_expires_at' => null])->save();

            return [$sender, match (true) {
                $expired => 'invalid_token',
                $sender->isQuarantined($now) => 'quarantined',
                $sender->linked_at !== null => 'already_linked',
                default => null,
            }];
        });

        if ($refusal) {
            throw new SenderLinkException($refusal);
        }

        $driver = $this->channels->channel($sender->channel);

        if (! $driver instanceof LinksAccounts) {
            throw new SenderLinkException('not_supported');
        }

        $account = $driver->link($sender, $credentials);

        if ($account !== $sender->account_id) {
            $sender->startWarmup(Sender::WARMUP_NEW, $now);
        }

        $sender->forceFill(['account_id' => $account, 'linked_at' => $now, 'is_active' => true])->save();

        return $sender;
    }

    public function unlink(Sender $sender): void
    {
        // account_id stays: linking the same account again must not restart the warm-up.
        $sender->forceFill([
            'linked_at' => null,
            'is_active' => false,
            'link_token_hash' => null,
            'link_token_expires_at' => null,
        ])->save();
    }
}
