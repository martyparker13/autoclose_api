<?php

namespace Tests\Feature\Reporting;

use App\Models\Deal;
use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealerReportRankingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dealer_admin_can_fetch_top_vehicles_and_top_staff_reports(): void
    {
        $dealer = Dealer::factory()->create();
        $otherDealer = Dealer::factory()->create();

        $admin = User::factory()->dealerAdmin()->create(['dealer_id' => $dealer->id]);
        $staffA = User::factory()->dealerStaff()->create(['dealer_id' => $dealer->id, 'name' => 'Alice']);
        $staffB = User::factory()->dealerStaff()->create(['dealer_id' => $dealer->id, 'name' => 'Bob']);

        $buyerA = User::factory()->create(['dealer_id' => $dealer->id, 'role' => 'buyer']);
        $buyerB = User::factory()->create(['dealer_id' => $dealer->id, 'role' => 'buyer']);

        $vehicleA = Vehicle::factory()->create(['dealer_id' => $dealer->id, 'year' => 2024, 'make' => 'Toyota', 'model' => 'Camry']);
        $vehicleB = Vehicle::factory()->create(['dealer_id' => $dealer->id, 'year' => 2023, 'make' => 'Honda', 'model' => 'Civic']);
        $otherVehicle = Vehicle::factory()->create(['dealer_id' => $otherDealer->id]);

        Deal::factory()->create([
            'dealer_id' => $dealer->id,
            'vehicle_id' => $vehicleA->id,
            'buyer_id' => $buyerA->id,
            'salesperson_id' => $staffA->id,
            'status' => 'delivered',
            'sale_price' => 3000000,
        ]);

        Deal::factory()->create([
            'dealer_id' => $dealer->id,
            'vehicle_id' => $vehicleA->id,
            'buyer_id' => $buyerB->id,
            'salesperson_id' => $staffA->id,
            'status' => 'delivered',
            'sale_price' => 2800000,
        ]);

        Deal::factory()->create([
            'dealer_id' => $dealer->id,
            'vehicle_id' => $vehicleB->id,
            'buyer_id' => $buyerA->id,
            'salesperson_id' => $staffB->id,
            'status' => 'delivered',
            'sale_price' => 2400000,
        ]);

        Deal::factory()->create([
            'dealer_id' => $otherDealer->id,
            'vehicle_id' => $otherVehicle->id,
            'buyer_id' => User::factory()->create(['dealer_id' => $otherDealer->id, 'role' => 'buyer'])->id,
            'status' => 'delivered',
            'sale_price' => 5000000,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/dealer/reports/top-vehicles?limit=5')
            ->assertOk()
            ->assertJsonStructure(['data' => [['vehicle_id', 'year', 'make', 'model', 'deals', 'revenue_cents']]])
            ->assertJsonPath('data.0.vehicle_id', $vehicleA->id)
            ->assertJsonPath('data.0.deals', 2);

        $this->actingAs($admin)
            ->getJson('/api/v1/dealer/reports/top-staff?limit=5')
            ->assertOk()
            ->assertJsonStructure(['data' => [['salesperson_id', 'name', 'deals', 'revenue_cents']]])
            ->assertJsonPath('data.0.salesperson_id', $staffA->id)
            ->assertJsonPath('data.0.deals', 2);
    }
}
