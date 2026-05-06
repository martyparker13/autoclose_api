<?php

namespace Database\Factories;

use App\Models\Dealer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Dealer>
 */
class DealerFactory extends Factory
{
    protected $model = Dealer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->company().' Auto';
        $slug = Str::slug($name).'-'.$this->faker->unique()->numberBetween(100, 999);

        return [
            'name'                 => $name,
            'slug'                 => $slug,
            'subdomain'            => Str::slug($name),
            'custom_domain'        => null,
            'logo_url'             => null,
            'primary_color'        => '#01696f',
            'phone'                => $this->faker->phoneNumber(),
            'email'                => $this->faker->companyEmail(),
            'address'              => $this->faker->streetAddress(),
            'city'                 => $this->faker->city(),
            'state'                => $this->faker->stateAbbr(),
            'zip'                  => $this->faker->postcode(),
            'license_number'       => strtoupper($this->faker->bothify('DLR-####??')),
            'dms_provider'         => 'manual',
            'subscription_plan'    => 'professional',
            'subscription_status'  => 'active',
            'is_active'            => true,
            'feature_flags'        => [
                'financing_enabled'    => true,
                'trade_in_enabled'     => true,
                'home_delivery_enabled'=> false,
                'fi_products_enabled'  => true,
                'dms_sync_enabled'     => false,
            ],
        ];
    }
}
