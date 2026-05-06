<?php

namespace Database\Seeders;

use App\Models\Dealer;
use App\Models\FiProduct;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMedia;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Super admin
        User::factory()->superAdmin()->create([
            'name'  => 'Super Admin',
            'email' => 'super@autoclose.test',
        ]);

        // Demo dealer
        $dealer = Dealer::factory()->create([
            'name'      => 'AutoClose Demo Motors',
            'slug'      => 'demo-motors',
            'subdomain' => 'demo',
            'email'     => 'admin@demo.autoclose.test',
            'feature_flags' => [
                'financing_enabled'     => true,
                'trade_in_enabled'      => true,
                'home_delivery_enabled' => true,
                'fi_products_enabled'   => true,
                'dms_sync_enabled'      => false,
            ],
        ]);

        // Dealer admin
        User::factory()->dealerAdmin()->create([
            'name'      => 'Demo Admin',
            'email'     => 'admin@autoclose.test',
            'dealer_id' => $dealer->id,
        ]);

        // Dealer staff
        User::factory()->dealerStaff()->create([
            'name'      => 'Demo Staff',
            'email'     => 'staff@autoclose.test',
            'dealer_id' => $dealer->id,
        ]);

        // Buyer
        User::factory()->create([
            'name'  => 'Test Buyer',
            'email' => 'buyer@autoclose.test',
            'role'  => 'buyer',
        ]);

        // Vehicles with primary photos
        $vehicles = Vehicle::factory(20)->create(['dealer_id' => $dealer->id]);

        foreach ($vehicles as $vehicle) {
            VehicleMedia::create([
                'vehicle_id' => $vehicle->id,
                'type'       => 'photo',
                'url'        => 'https://placehold.co/800x500/01696f/white?text='.$vehicle->year.'+'.$vehicle->make,
                'sort_order' => 0,
                'is_primary' => true,
                'label'      => 'Primary',
            ]);
        }

        // F&I products
        $fiTypes = [
            ['name' => 'Extended Warranty', 'type' => 'warranty', 'price' => 149900, 'cost' => 70000],
            ['name' => 'GAP Insurance', 'type' => 'gap', 'price' => 79900, 'cost' => 30000],
            ['name' => 'Tire & Wheel Protection', 'type' => 'tire_wheel', 'price' => 69900, 'cost' => 25000],
            ['name' => 'Paint Protection', 'type' => 'paint_protection', 'price' => 89900, 'cost' => 35000],
        ];

        foreach ($fiTypes as $product) {
            FiProduct::create(array_merge($product, [
                'dealer_id'   => $dealer->id,
                'provider'    => 'AutoClose F&I',
                'term_months' => 36,
                'is_active'   => true,
            ]));
        }
    }
}
