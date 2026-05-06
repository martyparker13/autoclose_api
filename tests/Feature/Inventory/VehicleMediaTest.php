<?php

namespace Tests\Feature\Inventory;

use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VehicleMediaTest extends TestCase
{
    use RefreshDatabase;

    private Dealer $dealer;
    private User $admin;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->dealer  = Dealer::factory()->create(['subdomain' => 'testdealer', 'custom_domain' => 'dealer.test']);
        $this->admin   = User::factory()->dealerAdmin()->create(['dealer_id' => $this->dealer->id]);
        $this->vehicle = Vehicle::factory()->create(['dealer_id' => $this->dealer->id]);
    }

    private function withDealer(): self
    {
        return $this->withHeaders(['X-Dealer-Domain' => 'dealer.test']);
    }

    public function test_dealer_staff_can_upload_media(): void
    {
        $file = UploadedFile::fake()->image('car.jpg', 800, 600);

        $this->withDealer()
            ->actingAs($this->admin)
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/media", [
                'file'  => $file,
                'type'  => 'photo',
                'label' => 'Front view',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'photo')
            ->assertJsonPath('data.label', 'Front view');

        $this->assertDatabaseHas('vehicle_media', [
            'vehicle_id' => $this->vehicle->id,
            'type'       => 'photo',
            'label'      => 'Front view',
        ]);
    }

    public function test_first_uploaded_media_is_primary(): void
    {
        $file = UploadedFile::fake()->image('car.jpg');

        $this->withDealer()
            ->actingAs($this->admin)
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/media", ['file' => $file])
            ->assertStatus(201);

        $this->assertDatabaseHas('vehicle_media', [
            'vehicle_id' => $this->vehicle->id,
            'is_primary' => true,
        ]);
    }

    public function test_second_upload_is_not_primary(): void
    {
        VehicleMedia::factory()->create(['vehicle_id' => $this->vehicle->id, 'is_primary' => true]);
        $file = UploadedFile::fake()->image('car2.jpg');

        $response = $this->withDealer()
            ->actingAs($this->admin)
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/media", ['file' => $file])
            ->assertStatus(201);

        $this->assertEquals(false, $response->json('data.is_primary'));
    }

    public function test_can_reorder_media(): void
    {
        $m1 = VehicleMedia::factory()->create(['vehicle_id' => $this->vehicle->id, 'sort_order' => 0, 'is_primary' => true]);
        $m2 = VehicleMedia::factory()->create(['vehicle_id' => $this->vehicle->id, 'sort_order' => 1]);

        $this->withDealer()
            ->actingAs($this->admin)
            ->patchJson("/api/v1/vehicles/{$this->vehicle->id}/media/reorder", [
                'order' => [$m2->id, $m1->id],
            ])
            ->assertStatus(204);

        $this->assertDatabaseHas('vehicle_media', ['id' => $m2->id, 'sort_order' => 0, 'is_primary' => true]);
        $this->assertDatabaseHas('vehicle_media', ['id' => $m1->id, 'sort_order' => 1, 'is_primary' => false]);
    }

    public function test_can_delete_media(): void
    {
        $media = VehicleMedia::factory()->create(['vehicle_id' => $this->vehicle->id]);

        $this->withDealer()
            ->actingAs($this->admin)
            ->deleteJson("/api/v1/vehicles/{$this->vehicle->id}/media/{$media->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('vehicle_media', ['id' => $media->id]);
    }

    public function test_cannot_delete_media_from_another_dealer(): void
    {
        $otherDealer  = Dealer::factory()->create(['subdomain' => 'other']);
        $otherVehicle = Vehicle::factory()->create(['dealer_id' => $otherDealer->id]);
        $media        = VehicleMedia::factory()->create(['vehicle_id' => $otherVehicle->id]);

        $this->withDealer()
            ->actingAs($this->admin)
            ->deleteJson("/api/v1/vehicles/{$otherVehicle->id}/media/{$media->id}")
            ->assertStatus(404);
    }

    public function test_buyer_cannot_upload_media(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'dealer_id' => $this->dealer->id]);
        $file  = UploadedFile::fake()->image('car.jpg');

        $this->withDealer()
            ->actingAs($buyer)
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/media", ['file' => $file])
            ->assertStatus(403);
    }

    public function test_upload_validates_file_type(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $this->withDealer()
            ->actingAs($this->admin)
            ->postJson("/api/v1/vehicles/{$this->vehicle->id}/media", ['file' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }
}
