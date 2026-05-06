<?php

namespace Database\Factories;

use App\Models\Dealer;
use App\Models\FiProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiProduct>
 */
class FiProductFactory extends Factory
{
    protected $model = FiProduct::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'dealer_id'   => Dealer::factory(),
            'name'        => $this->faker->randomElement([
                'Extended Warranty', 'GAP Insurance', 'Tire & Wheel Protection',
                'Paint Sealant', 'Rust Proofing', 'Credit Life Insurance',
            ]),
            'type'        => $this->faker->randomElement(['warranty', 'gap', 'tire_wheel', 'paint_protection', 'key_replacement', 'credit_life', 'credit_disability']),
            'provider'    => $this->faker->company(),
            'description' => $this->faker->sentence(),
            'cost'        => $this->faker->numberBetween(20000, 60000), // cents
            'price'       => $this->faker->numberBetween(50000, 150000), // cents
            'term_months' => $this->faker->randomElement([12, 24, 36, 48, 60, null]),
            'is_active'   => true,
        ];
    }
}
