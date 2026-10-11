<?php

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Campaign> */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        return [
            'name' => fake()->catchPhrase(),
            'body' => 'Hi {{first_name}}! **-20%** on your next visit this week.',
            'channels' => [['channel' => 'whatsapp', 'fallback' => false], ['channel' => 'sms', 'fallback' => true]],
            'audience' => ['tags' => []],
            'daily_cap' => 50,
            'status' => CampaignStatus::Draft,
        ];
    }

    /** @param list<array{channel: string, fallback?: bool}> $chain */
    public function channels(array $chain): static
    {
        return $this->state(['channels' => $chain]);
    }

    public function running(): static
    {
        return $this->state(['status' => CampaignStatus::Running, 'launched_at' => now()]);
    }
}
