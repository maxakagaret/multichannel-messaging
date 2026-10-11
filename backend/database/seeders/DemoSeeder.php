<?php

namespace Database\Seeders;

use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Enums\MessageStatus;
use App\Messaging\Planning\CampaignLauncher;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Sender;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Synthetic demo data: contacts with tags, a sender pool, a finished campaign
 * with a realistic delivery history (for the dashboards) and a running one
 * that the scheduler keeps sending while you watch.
 */
class DemoSeeder extends Seeder
{
    private const TAGS = ['vip', 'newsletter', 'lapsed', 'new', 'weekend'];

    public function run(): void
    {
        fake()->seed(2026);

        $contacts = Contact::factory()->count(800)->create();
        $contacts->each(fn (Contact $c) => $c->syncTags(fake()->randomElements(self::TAGS, fake()->numberBetween(0, 2))));

        $senders = collect([
            [Channel::WhatsApp, 'WA line 1'], [Channel::WhatsApp, 'WA line 2'], [Channel::WhatsApp, 'WA line 3'],
            [Channel::Telegram, 'Telegram bot'], [Channel::Sms, 'SMS short code'], [Channel::Email, 'Mail relay'],
        ])->map(fn ($s) => Sender::create(['channel' => $s[0], 'name' => $s[1]]));

        $this->historicalCampaign($contacts, $senders);

        $running = Campaign::create([
            'name' => 'Spring re-engagement',
            'body' => "Hi {{first_name}}, we miss you!\n**Book this week** and get a free upgrade.",
            'channels' => [
                ['channel' => 'whatsapp', 'fallback' => false],
                ['channel' => 'sms', 'fallback' => true],
            ],
            'audience' => ['tags' => ['lapsed', 'weekend']],
            'daily_cap' => 120,
            'status' => CampaignStatus::Draft,
        ]);
        app(CampaignLauncher::class)->launch($running);

        Campaign::create([
            'name' => 'VIP preview night (draft)',
            'body' => 'Dear {{name}}, you are invited to a **private preview**.',
            'channels' => [
                ['channel' => 'telegram', 'fallback' => false],
                ['channel' => 'email', 'fallback' => true],
            ],
            'audience' => ['tags' => ['vip']],
            'daily_cap' => 50,
            'status' => CampaignStatus::Draft,
        ]);
    }

    /** A completed campaign with a week of synthetic delivery history. */
    private function historicalCampaign($contacts, $senders): void
    {
        $campaign = Campaign::create([
            'name' => 'Winter newsletter',
            'body' => 'Hello {{first_name}}, our **winter menu** is here.',
            'channels' => [
                ['channel' => 'whatsapp', 'fallback' => false],
                ['channel' => 'email', 'fallback' => false],
                ['channel' => 'sms', 'fallback' => true],
            ],
            'audience' => ['tags' => ['newsletter']],
            'daily_cap' => 80,
            'status' => CampaignStatus::Completed,
            'launched_at' => now()->subDays(7),
            'completed_at' => now()->subDay(),
        ]);

        $rows = [];
        $events = [];
        $start = CarbonImmutable::now()->subDays(7);

        foreach ($contacts->where('marketing_consent', true)->take(420)->values() as $i => $contact) {
            foreach ([Channel::WhatsApp, Channel::Email] as $channel) {
                $recipient = $contact->addressFor($channel);
                if (! $recipient) {
                    continue;
                }

                $sentAt = $start->addMinutes($i * 20 + fake()->numberBetween(0, 15));
                $roll = fake()->numberBetween(1, 100);
                [$status, $error] = match (true) {
                    $roll <= 7 => [MessageStatus::Failed, $channel === Channel::Email ? 'mailbox_does_not_exist' : 'not_registered'],
                    $roll <= 40 => [MessageStatus::Read, null],
                    $roll <= 96 => [MessageStatus::Delivered, null],
                    default => [MessageStatus::Sent, null],
                };
                $deliveredAt = in_array($status, [MessageStatus::Delivered, MessageStatus::Read], true)
                    ? $sentAt->addSeconds(fake()->numberBetween($channel === Channel::Email ? 2 : 1, $channel === Channel::Email ? 40 : 12))
                    : null;

                $rows[] = [
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'sender_id' => $senders->firstWhere('channel', $channel)?->id,
                    'channel' => $channel->value,
                    'recipient' => $recipient,
                    'body' => 'Hello '.strtok($contact->name, ' ').', our winter menu is here.',
                    'status' => $status->value,
                    'attempts' => 1,
                    'provider_message_id' => $channel->value.'-'.Str::uuid(),
                    'error_code' => $error,
                    'idempotency_key' => "c{$campaign->id}:ct{$contact->id}:{$channel->value}",
                    'scheduled_for' => $sentAt,
                    'sent_at' => $sentAt,
                    'delivered_at' => $deliveredAt,
                    'read_at' => $status === MessageStatus::Read ? $deliveredAt?->addMinutes(fake()->numberBetween(1, 300)) : null,
                    'failed_at' => $status === MessageStatus::Failed ? $sentAt->addSeconds(30) : null,
                    'created_at' => $sentAt,
                    'updated_at' => $sentAt,
                ];
            }
        }

        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('messages')->insert($chunk);
        }

        DB::table('messages')->where('campaign_id', $campaign->id)->orderBy('id')
            ->each(function ($m) use (&$events) {
                foreach (['queued' => $m->scheduled_for, 'sent' => $m->sent_at, 'delivered' => $m->delivered_at, 'read' => $m->read_at, 'failed' => $m->failed_at] as $status => $at) {
                    if ($at) {
                        $events[] = ['message_id' => $m->id, 'status' => $status, 'source' => $status === 'queued' ? 'dispatcher' : ($status === 'sent' ? 'job' : 'webhook'), 'occurred_at' => $at];
                    }
                }
            });

        foreach (array_chunk($events, 500) as $chunk) {
            DB::table('message_events')->insert($chunk);
        }
    }
}
