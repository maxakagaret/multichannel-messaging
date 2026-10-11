<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contact> */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // +1 555 01xx range is reserved for fiction — never a real phone.
            'phone' => '+1555'.fake()->unique()->numerify('01#####'),
            'email' => fake()->unique()->userName().'@example.test',
            'telegram_chat_id' => fake()->boolean(60) ? (string) fake()->unique()->numberBetween(100000000, 999999999) : null,
            'marketing_consent' => fake()->boolean(92),
        ];
    }

    public function consented(): static
    {
        return $this->state(['marketing_consent' => true]);
    }

    public function withoutPhone(): static
    {
        return $this->state(['phone' => null]);
    }
}
