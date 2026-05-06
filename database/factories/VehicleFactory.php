<?php

namespace Database\Factories;

use App\Models\Dealer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    private static array $makes = [
        'Toyota' => ['Camry', 'Corolla', 'RAV4', 'Highlander', 'Tacoma', 'Tundra'],
        'Honda'  => ['Civic', 'Accord', 'CR-V', 'Pilot', 'Odyssey'],
        'Ford'   => ['F-150', 'Explorer', 'Escape', 'Mustang', 'Bronco'],
        'Chevrolet' => ['Silverado', 'Equinox', 'Traverse', 'Malibu', 'Tahoe'],
        'BMW'    => ['3 Series', '5 Series', 'X3', 'X5', 'M3'],
        'Tesla'  => ['Model 3', 'Model Y', 'Model S', 'Model X'],
    ];

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $make  = array_rand(self::$makes);
        $model = $this->faker->randomElement(self::$makes[$make]);
        $year  = $this->faker->numberBetween(2018, 2025);
        $price = $this->faker->numberBetween(15000, 95000) * 100; // cents

        return [
            'dealer_id'      => Dealer::factory(),
            'vin'            => strtoupper($this->faker->bothify('1HGBH41J?MN10###?')),
            'stock_number'   => strtoupper($this->faker->bothify('STK-#####')),
            'year'           => $year,
            'make'           => $make,
            'model'          => $model,
            'trim'           => $this->faker->randomElement(['Base', 'Sport', 'Limited', 'Premium', 'XLE', 'SE']),
            'body_style'     => $this->faker->randomElement(['Sedan', 'SUV', 'Truck', 'Coupe', 'Minivan', 'Hatchback']),
            'exterior_color' => $this->faker->safeColorName(),
            'interior_color' => $this->faker->randomElement(['Black', 'Gray', 'Beige', 'Brown', 'White']),
            'mileage'        => $this->faker->numberBetween(0, 80000),
            'condition'      => $this->faker->randomElement(['new', 'used', 'certified']),
            'price'          => $price,
            'msrp'           => $price + $this->faker->numberBetween(500, 3000) * 100,
            'internet_price' => $price - $this->faker->numberBetween(200, 1500) * 100,
            'cost'           => $price - $this->faker->numberBetween(2000, 8000) * 100,
            'transmission'   => $this->faker->randomElement(['Automatic', 'Manual', 'CVT']),
            'engine'         => $this->faker->randomElement(['2.0L 4-Cylinder', '3.5L V6', '5.0L V8', 'Electric']),
            'drivetrain'     => $this->faker->randomElement(['FWD', 'AWD', '4WD', 'RWD']),
            'fuel_type'      => $this->faker->randomElement(['Gasoline', 'Hybrid', 'Electric', 'Diesel']),
            'doors'          => $this->faker->randomElement([2, 4]),
            'cylinders'      => $this->faker->randomElement([4, 6, 8]),
            'status'         => 'available',
            'description'    => $this->faker->paragraphs(2, true),
        ];
    }

    public function available(): static
    {
        return $this->state(['status' => 'available']);
    }

    public function sold(): static
    {
        return $this->state(['status' => 'sold']);
    }
}
