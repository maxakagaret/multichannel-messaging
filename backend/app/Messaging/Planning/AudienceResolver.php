<?php

namespace App\Messaging\Planning;

use App\Enums\Channel;
use App\Models\Campaign;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who receives a campaign: contacts with marketing consent, matching any of the
 * audience tags (no tags = everybody), and reachable in at least one primary
 * channel. One query, no per-contact checks.
 */
class AudienceResolver
{
    /** @return list<int> */
    public function contactIds(Campaign $campaign): array
    {
        $tags = $campaign->audience['tags'] ?? [];

        return Contact::query()
            ->where('marketing_consent', true)
            ->when($tags, fn (Builder $q) => $q->whereExists(fn ($sub) => $sub
                ->from('contact_tags')
                ->whereColumn('contact_tags.contact_id', 'contacts.id')
                ->whereIn('contact_tags.tag', $tags)))
            ->where(function (Builder $q) use ($campaign) {
                foreach ($campaign->primaryChannels() as $channel) {
                    $q->orWhereNotNull($this->addressColumn($channel));
                }
            })
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private function addressColumn(Channel $channel): string
    {
        return match ($channel) {
            Channel::Sms, Channel::WhatsApp => 'phone',
            Channel::Email => 'email',
            Channel::Telegram => 'telegram_chat_id',
        };
    }
}
