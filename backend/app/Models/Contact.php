<?php

namespace App\Models;

use App\Enums\Channel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Contact extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'marketing_consent' => 'boolean',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return DB::table('contact_tags')->where('contact_id', $this->id)->orderBy('tag')->pluck('tag')->all();
    }

    /** @param list<string> $tags */
    public function syncTags(array $tags): void
    {
        DB::table('contact_tags')->where('contact_id', $this->id)->delete();
        DB::table('contact_tags')->insert(
            array_map(fn (string $tag) => ['contact_id' => $this->id, 'tag' => $tag], array_values(array_unique($tags)))
        );
    }

    /** Address of this contact in the given channel, or null if unreachable there. */
    public function addressFor(Channel $channel): ?string
    {
        return match ($channel) {
            Channel::Sms, Channel::WhatsApp => $this->phone,
            Channel::Email => $this->email,
            Channel::Telegram => $this->telegram_chat_id,
        };
    }
}
