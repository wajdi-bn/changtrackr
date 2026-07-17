<?php

namespace Database\Factories;

use App\Models\DemoRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DemoRequest> */
class DemoRequestFactory extends Factory
{
    protected $model = DemoRequest::class;

    public function definition(): array
    {
        return [
            'reference' => 'DEMO-'.Str::upper(Str::random(10)),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->companyEmail(),
            'company_name' => fake()->company(),
            'phone' => fake()->phoneNumber(),
            'topic' => fake()->randomElement(DemoRequest::TOPICS),
            'estimated_stations' => fake()->numberBetween(1, 250),
            'message' => fake()->sentence(14),
            'status' => 'new',
            'consent_at' => now(),
        ];
    }
}
