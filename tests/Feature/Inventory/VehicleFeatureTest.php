<?php

namespace Tests\Feature\Inventory;

use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleFeatureTest extends TestCase
{
    use RefreshDatabase;

    private Dealer $dealer;
    private User $admin;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealer  = Dealer::factory()->create(['subdomain' => 'testdealer', 'custom_domain' => 'dealer.test']);
        $this->admin   = User::factory()->dealerAdmin()->create(['dealer_id' => $this->dealer->id]);
        $this->vehicle = Vehicle::factory()->create(['dealer_id' => $this->dealer->id]);
    }

    private function withDealer(): self
    {
        return $this->withHeaders(['X-Dealer-Domain' => 'dealer.test']);
    }

    public function test_dealer_staff_can_sync_features(): void
    {
        $this->withDealer()
            ->actingAs($this->admin)
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/features", [
                'features' => [
                    ['feature_name' => 'Sunroof',          'category' => 'Comfort'],
                    ['feature_name' => 'Heated Seats',     'category' => 'Comfort'],
                    ['feature_name' => 'Apple CarPlay',    'category' => 'Technology'],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonCount(3, 'data');

        $this->assertDatabaseHas('vehicle_features', [
            'vehicle_id'   => $this->vehicle->id,
            'feature_name' => 'Sunroof',
            'category'     => 'Comfort',
        ]);
    }

    public function test_syncing_features_replaces_existing(): void
    {
        VehicleFeature::factory()->create(['vehicle_id' => $this->vehicle->id, 'feature_name' => 'Old Feature']);

        $this->withDealer()
            ->actingAs($this->admin)
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/features", [
                'features' => [['feature_name' => 'New Feature', 'category' => null]],
            ])
            ->assertStatus(201);

        $this->assertDatabaseMissing('vehicle_features', ['feature_name' => 'Old Feature']);
        $this->assertDatabaseHas('vehicle_features', ['feature_name' => 'New Feature']);
    }

    public function test_can_delete_a_feature(): void
    {
        $feature = VehicleFeature::factory()->create(['vehicle_id' => $this->vehicle->id]);

        $this->withDealer()
            ->actingAs($this->admin)
            ->deleteJson("/api/v1/vehicles/{$this->vehicle->id}/features/{$feature->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('vehicle_features', ['id' => $feature->id]);
    }

    public function test_cannot_delete_feature_from_another_dealer(): void
    {
        $otherDealer  = Dealer::factory()->create(['subdomain' => 'other']);
        $otherVehicle = Vehicle::factory()->create(['dealer_id' => $otherDealer->id]);
        $feature      = VehicleFeature::factory()->create(['vehicle_id' => $otherVehicle->id]);

        $this->withDealer()
            ->actingAs($this->admin)
            ->deleteJson("/api/v1/vehicles/{$otherVehicle->id}/features/{$feature->id}")
            ->assertStatus(404);
    }

    public function test_buyer_cannot_sync_features(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'dealer_id' => $this->dealer->id]);

        $this->withDealer()
            ->actingAs($buyer)
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/features", [
                'features' => [['feature_name' => 'Sunroof']],
            ])
            ->assertStatus(403);
    }

    public function test_sync_requires_features_array(): void
    {
        $this->withDealer()
            ->actingAs($this->admin)
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/features", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['features']);
    }

    public function test_sync_requires_feature_name(): void
    {
        $this->withDealer()
            ->actingAs($this->admin)
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/features", [
                'features' => [['category' => 'Safety']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['features.0.feature_name']);
    }
}
