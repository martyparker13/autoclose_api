<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\Dealer;
use App\Models\DeliveryAppointment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryAppointment>
 */
class DeliveryAppointmentFactory extends Factory
{
    protected $model = DeliveryAppointment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'deal_id'      => Deal::factory(),
            'dealer_id'    => Dealer::factory(),
            'type'         => $this->faker->randomElement(['home_delivery', 'lot_pickup']),
            'scheduled_at' => $this->faker->dateTimeBetween('+1 day', '+30 days'),
            'address'      => null,
            'driver_id'    => null,
            'status'       => 'scheduled',
            'notes'        => null,
        ];
    }

    public function homeDelivery(): static
    {
        return $this->state(fn (array $attrs) => [
            'type'    => 'home_delivery',
            'address' => [
                'street' => $this->faker->streetAddress(),
                'city'   => $this->faker->city(),
                'state'  => $this->faker->stateAbbr(),
                'zip'    => $this->faker->postcode(),
            ],
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => 'completed',
        ]);
    }
}
