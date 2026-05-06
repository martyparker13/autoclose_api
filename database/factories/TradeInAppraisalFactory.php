<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\Dealer;
use App\Models\TradeInAppraisal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradeInAppraisal>
 */
class TradeInAppraisalFactory extends Factory
{
    protected $model = TradeInAppraisal::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'deal_id'          => Deal::factory(),
            'dealer_id'        => Dealer::factory(),
            'year'             => $this->faker->numberBetween(2010, 2022),
            'make'             => $this->faker->randomElement(['Toyota', 'Honda', 'Ford', 'Chevrolet', 'Nissan']),
            'model'            => $this->faker->randomElement(['Camry', 'Accord', 'F-150', 'Silverado', 'Altima']),
            'trim'             => $this->faker->randomElement(['LE', 'SE', 'XLE', 'Sport', null]),
            'mileage'          => $this->faker->numberBetween(20000, 120000),
            'vin'              => null,
            'condition'        => $this->faker->randomElement(['excellent', 'good', 'fair', 'poor']),
            'kbb_value'        => null,
            'black_book_value' => null,
            'dealer_offer'     => null,
            'accepted'         => false,
            'responded_at'     => null,
        ];
    }

    public function withOffer(): static
    {
        return $this->state(fn (array $attrs) => [
            'dealer_offer' => $this->faker->numberBetween(5000, 20000) * 100,
            'responded_at' => now(),
        ]);
    }
}
