<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Models\Sender;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Sender> */
class SenderFactory extends Factory
{
    protected $model = Sender::class;

    public function definition(): array
    {
        return [
            'channel' => Channel::WhatsApp,
            'name' => 'Sender '.fake()->unique()->numberBetween(1, 9999),
            'is_active' => true,
            'last_used_at' => null,
        ];
    }

    public function channel(Channel $channel): static
    {
        return $this->state(['channel' => $channel]);
    }
}
