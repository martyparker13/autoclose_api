<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $salePrice = $this->faker->numberBetween(15000, 80000) * 100;
        $down      = $this->faker->numberBetween(1000, 10000) * 100;

        return [
            'dealer_id'       => Dealer::factory(),
            'vehicle_id'      => Vehicle::factory(),
            'buyer_id'        => User::factory(),
            'salesperson_id'  => null,
            'status'          => 'draft',
            'source'          => $this->faker->randomElement(['web', 'mobile', 'in_store']),
            'sale_price'      => $salePrice,
            'down_payment'    => $down,
            'finance_amount'  => $salePrice - $down,
            'apr'             => null,
            'term_months'     => null,
            'monthly_payment' => null,
            'total_fi_income' => 0,
            'trade_in_value'  => 0,
            'trade_in_vehicle' => null,
            'lender'          => null,
            'notes'           => null,
        ];
    }

    /** Draft status shorthand */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'draft']);
    }

    /** Credit-approved shorthand */
    public function creditApproved(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'credit_approved']);
    }
}
