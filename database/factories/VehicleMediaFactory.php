<?php

namespace Database\Factories;

use App\Models\Vehicle;
use App\Models\VehicleMedia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleMedia>
 */
class VehicleMediaFactory extends Factory
{
    protected $model = VehicleMedia::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'type'       => 'photo',
            'url'        => 'https://s3.example.com/vehicles/1/media/' . $this->faker->uuid() . '.jpg',
            'sort_order' => 0,
            'is_primary' => false,
            'label'      => null,
        ];
    }

    public function primary(): static
    {
        return $this->state(['is_primary' => true, 'sort_order' => 0]);
    }

    public function video(): static
    {
        return $this->state([
            'type' => 'video',
            'url'  => 'https://s3.example.com/vehicles/1/media/' . $this->faker->uuid() . '.mp4',
        ]);
    }
}
