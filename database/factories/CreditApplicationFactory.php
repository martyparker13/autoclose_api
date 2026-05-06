<?php

namespace Database\Factories;

use App\Models\CreditApplication;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditApplication>
 */
class CreditApplicationFactory extends Factory
{
    protected $model = CreditApplication::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'deal_id'            => Deal::factory(),
            'buyer_id'           => User::factory(),
            'ssn_encrypted'      => '000000000', // encrypted at model layer
            'dob'                => $this->faker->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
            'annual_income'      => $this->faker->numberBetween(30000, 150000) * 100,
            'employment_status'  => $this->faker->randomElement(['employed', 'self_employed', 'retired']),
            'employer_name'      => $this->faker->company(),
            'employer_phone'     => $this->faker->numerify('##########'),
            'monthly_housing'    => $this->faker->numberBetween(500, 3000) * 100,
            'housing_status'     => $this->faker->randomElement(['own', 'rent', 'other']),
            'years_at_employer'  => $this->faker->numberBetween(0, 20),
            'credit_score_range' => $this->faker->randomElement(['excellent', 'good', 'fair', 'poor']),
            'bureau_pull_type'   => 'soft',
            'decision'           => 'pending',
            'approved_amount'    => null,
            'approved_apr'       => null,
            'approved_term'      => null,
            'submitted_at'       => now(),
            'decided_at'         => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attrs) => [
            'decision'       => 'approved',
            'approved_amount'=> $this->faker->numberBetween(20000, 60000) * 100,
            'approved_apr'   => $this->faker->randomFloat(3, 2.5, 12.0),
            'approved_term'  => $this->faker->randomElement([36, 48, 60, 72]),
            'decided_at'     => now(),
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (array $attrs) => [
            'decision'   => 'declined',
            'decided_at' => now(),
        ]);
    }
}
