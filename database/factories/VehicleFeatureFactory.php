<?php

namespace Database\Factories;

use App\Models\Vehicle;
use App\Models\VehicleFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleFeature>
 */
class VehicleFeatureFactory extends Factory
{
    protected $model = VehicleFeature::class;

    private static array $categories = [
        'Safety'       => ['Lane Departure Warning', 'Blind Spot Monitor', 'Forward Collision Warning', 'Backup Camera', 'ABS Brakes'],
        'Technology'   => ['Apple CarPlay', 'Android Auto', 'Bluetooth', 'Navigation', 'USB Ports', 'Wireless Charging'],
        'Comfort'      => ['Heated Seats', 'Ventilated Seats', 'Sunroof', 'Panoramic Roof', 'Remote Start', 'Keyless Entry'],
        'Performance'  => ['Sport Mode', 'Paddle Shifters', 'Performance Exhaust', 'Sport Suspension'],
        'Exterior'     => ['Alloy Wheels', 'LED Headlights', 'Fog Lights', 'Power Mirrors', 'Running Boards'],
    ];

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $category = $this->faker->randomElement(array_keys(self::$categories));
        $feature  = $this->faker->randomElement(self::$categories[$category]);

        return [
            'vehicle_id'   => Vehicle::factory(),
            'category'     => $category,
            'feature_name' => $feature,
        ];
    }
}
