<?php

namespace Tests\Feature\Inventory;

use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleTest extends TestCase
{
    use RefreshDatabase;

    private Dealer $dealer;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealer = Dealer::factory()->create(['subdomain' => 'testdealer', 'custom_domain' => 'dealer.test']);
        $this->admin  = User::factory()->dealerAdmin()->create(['dealer_id' => $this->dealer->id]);
    }

    private function withDealer(): self
    {
        return $this->withHeaders(['X-Dealer-Domain' => 'dealer.test']);
    }

    public function test_can_list_vehicles_publicly(): void
    {
        Vehicle::factory()->count(3)->create([
            'dealer_id' => $this->dealer->id,
            'status'    => 'available',
        ]);

        $this->withDealer()
            ->getJson('/api/v1/public/vehicles')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_can_show_vehicle(): void
    {
        $vehicle = Vehicle::factory()->create(['dealer_id' => $this->dealer->id]);

        $this->withDealer()
            ->getJson("/api/v1/public/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $vehicle->id);
    }

    public function test_dealer_admin_can_create_vehicle(): void
    {
        $payload = [
            'year'      => 2023,
            'make'      => 'Toyota',
            'model'     => 'Camry',
            'mileage'   => 0,
            'condition' => 'new',
            'price'     => 3000000, // $30,000 in cents
        ];

        $this->withDealer()
            ->actingAs($this->admin)
            ->postJson('/api/v1/vehicles', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.make', 'Toyota')
            ->assertJsonPath('data.price', 30000);

        $this->assertDatabaseHas('vehicles', [
            'dealer_id' => $this->dealer->id,
            'make'      => 'Toyota',
        ]);
    }

    public function test_buyer_cannot_create_vehicle(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'dealer_id' => $this->dealer->id]);

        $this->withDealer()
            ->actingAs($buyer)
            ->postJson('/api/v1/vehicles', [
                'year' => 2023, 'make' => 'Toyota', 'model' => 'Camry',
                'mileage' => 0, 'condition' => 'new', 'price' => 3000000,
            ])
            ->assertStatus(403);
    }

    public function test_dealer_admin_can_update_vehicle(): void
    {
        $vehicle = Vehicle::factory()->create(['dealer_id' => $this->dealer->id]);

        $this->withDealer()
            ->actingAs($this->admin)
            ->patchJson("/api/v1/vehicles/{$vehicle->id}", ['status' => 'hold'])
            ->assertOk()
            ->assertJsonPath('data.status', 'hold');
    }

    public function test_dealer_admin_can_delete_vehicle(): void
    {
        $vehicle = Vehicle::factory()->create(['dealer_id' => $this->dealer->id]);

        $this->withDealer()
            ->actingAs($this->admin)
            ->deleteJson("/api/v1/vehicles/{$vehicle->id}")
            ->assertStatus(204);

        $this->assertSoftDeleted('vehicles', ['id' => $vehicle->id]);
    }

    public function test_cannot_access_another_dealers_vehicle(): void
    {
        $otherDealer  = Dealer::factory()->create(['subdomain' => 'other']);
        $otherVehicle = Vehicle::factory()->create(['dealer_id' => $otherDealer->id]);

        $this->withDealer()
            ->getJson("/api/v1/public/vehicles/{$otherVehicle->id}")
            ->assertStatus(404);
    }

    public function test_vehicles_are_filtered_by_status(): void
    {
        Vehicle::factory()->count(2)->create(['dealer_id' => $this->dealer->id, 'status' => 'available']);
        Vehicle::factory()->create(['dealer_id' => $this->dealer->id, 'status' => 'sold']);

        $this->withDealer()
            ->getJson('/api/v1/public/vehicles?status=available')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_store_requires_mandatory_fields(): void
    {
        $this->withDealer()
            ->actingAs($this->admin)
            ->postJson('/api/v1/vehicles', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['year', 'make', 'model', 'mileage', 'condition', 'price']);
    }
}
