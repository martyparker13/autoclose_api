<?php

namespace Tests\Feature\Deal;

use App\Models\Deal;
use App\Models\Dealer;
use App\Models\FiProduct;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealTest extends TestCase
{
    use RefreshDatabase;

    private Dealer $dealer;
    private User $admin;
    private User $staff;
    private User $buyer;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealer = Dealer::factory()->create([
            'subdomain'     => 'testdealer',
            'custom_domain' => 'dealer.test',
        ]);

        $this->admin = User::factory()->dealerAdmin()->create(['dealer_id' => $this->dealer->id]);
        $this->staff = User::factory()->dealerStaff()->create(['dealer_id' => $this->dealer->id]);
        $this->buyer = User::factory()->create(['dealer_id' => $this->dealer->id]);

        $this->vehicle = Vehicle::factory()->create([
            'dealer_id' => $this->dealer->id,
            'status'    => 'available',
            'price'     => 3000000, // $30,000
        ]);
    }

    private function withDealer(): self
    {
        return $this->withHeaders(['X-Dealer-Domain' => 'dealer.test']);
    }

    // ── Buyer opens a deal ────────────────────────────────────────────────

    public function test_buyer_can_open_a_deal(): void
    {
        $this->withDealer()
            ->actingAs($this->buyer)
            ->postJson('/api/v1/deals', [
                'vehicle_id' => $this->vehicle->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.vehicle.id', $this->vehicle->id);

        $this->assertDatabaseHas('deals', [
            'buyer_id'   => $this->buyer->id,
            'vehicle_id' => $this->vehicle->id,
            'status'     => 'draft',
        ]);
    }

    public function test_buyer_cannot_open_deal_on_unavailable_vehicle(): void
    {
        $this->vehicle->update(['status' => 'sold']);

        $this->withDealer()
            ->actingAs($this->buyer)
            ->postJson('/api/v1/deals', [
                'vehicle_id' => $this->vehicle->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('vehicle_id');
    }

    public function test_buyer_cannot_open_duplicate_active_deal(): void
    {
        Deal::factory()->create([
            'dealer_id'  => $this->dealer->id,
            'vehicle_id' => $this->vehicle->id,
            'buyer_id'   => $this->buyer->id,
            'status'     => 'draft',
        ]);

        $this->withDealer()
            ->actingAs($this->buyer)
            ->postJson('/api/v1/deals', [
                'vehicle_id' => $this->vehicle->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('vehicle_id');
    }

    // ── Buyer views deals ─────────────────────────────────────────────────

    public function test_buyer_can_list_own_deals(): void
    {
        Deal::factory()->count(2)->create([
            'dealer_id' => $this->dealer->id,
            'vehicle_id' => $this->vehicle->id,
            'buyer_id'  => $this->buyer->id,
        ]);

        // Another buyer's deal — should not appear
        $otherBuyer = User::factory()->create(['dealer_id' => $this->dealer->id]);
        $otherVehicle = Vehicle::factory()->create(['dealer_id' => $this->dealer->id, 'status' => 'available']);
        Deal::factory()->create([
            'dealer_id'  => $this->dealer->id,
            'vehicle_id' => $otherVehicle->id,
            'buyer_id'   => $otherBuyer->id,
        ]);

        $this->withDealer()
            ->actingAs($this->buyer)
            ->getJson('/api/v1/deals')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_buyer_can_view_own_deal(): void
    {
        $deal = Deal::factory()->create([
            'dealer_id'  => $this->dealer->id,
            'vehicle_id' => $this->vehicle->id,
            'buyer_id'   => $this->buyer->id,
        ]);

        $this->withDealer()
            ->actingAs($this->buyer)
            ->getJson("/api/v1/deals/{$deal->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $deal->id);
    }

    public function test_buyer_cannot_view_another_buyers_deal(): void
    {
        $otherBuyer = User::factory()->create(['dealer_id' => $this->dealer->id]);
        $otherVehicle = Vehicle::factory()->create(['dealer_id' => $this->dealer->id, 'status' => 'available']);
        $deal = Deal::factory()->create([
            'dealer_id'  => $this->dealer->id,
            'vehicle_id' => $otherVehicle->id,
            'buyer_id'   => $otherBuyer->id,
        ]);

        $this->withDealer()
            ->actingAs($this->buyer)
            ->getJson("/api/v1/deals/{$deal->id}")
            ->assertNotFound();
    }

    // ── Buyer updates terms ───────────────────────────────────────────────

    public function test_buyer_can_update_deal_terms(): void
    {
        $deal = Deal::factory()->create([
            'dealer_id'  => $this->dealer->id,
            'vehicle_id' => $this->vehicle->id,
            'buyer_id'   => $this->buyer->id,
            'status'     => 'draft',
        ]);

        $this->withDealer()
            ->actingAs($this->buyer)
            ->patchJson("/api/v1/deals/{$deal->id}", [
                'down_payment' => 500000, // $5,000
            ])
            ->assertOk()
            ->assertJsonPath('data.down_payment', 5000);
    }

    // ── Dealer staff views deals ──────────────────────────────────────────

    public function test_dealer_staff_can_list_all_dealer_deals(): void
    {
        $v2 = Vehicle::factory()->create(['dealer_id' => $this->dealer->id, 'status' => 'available']);
        $b2 = User::factory()->create(['dealer_id' => $this->dealer->id]);
        Deal::factory()->create(['dealer_id' => $this->dealer->id, 'vehicle_id' => $this->vehicle->id, 'buyer_id' => $this->buyer->id]);
        Deal::factory()->create(['dealer_id' => $this->dealer->id, 'vehicle_id' => $v2->id, 'buyer_id' => $b2->id]);

        $this->withDealer()
            ->actingAs($this->staff)
            ->getJson('/api/v1/dealer/deals')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_dealer_staff_cannot_see_another_dealers_deals(): void
    {
        $other = Dealer::factory()->create(['subdomain' => 'other', 'custom_domain' => 'other.test']);
        $otherBuyer = User::factory()->create(['dealer_id' => $other->id]);
        $otherVehicle = Vehicle::factory()->create(['dealer_id' => $other->id, 'status' => 'available']);
        Deal::factory()->create(['dealer_id' => $other->id, 'vehicle_id' => $otherVehicle->id, 'buyer_id' => $otherBuyer->id]);

        // Staff's dealer has 0 deals
        $this->withDealer()
            ->actingAs($this->staff)
            ->getJson('/api/v1/dealer/deals')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── Dealer staff transitions ──────────────────────────────────────────

    public function test_dealer_staff_can_transition_deal_status(): void
    {
        $deal = Deal::factory()->create([
            'dealer_id'  => $this->dealer->id,
            'vehicle_id' => $this->vehicle->id,
            'buyer_id'   => $this->buyer->id,
            'status'     => 'draft',
        ]);

        $this->withDealer()
            ->actingAs($this->staff)
            ->patchJson("/api/v1/dealer/deals/{$deal->id}/transition", [
                'status' => 'credit_submitted',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'credit_submitted');
    }

    public function test_invalid_status_transition_returns_error(): void
    {
        $deal = Deal::factory()->create([
            'dealer_id'  => $this->dealer->id,
            'vehicle_id' => $this->vehicle->id,
            'buyer_id'   => $this->buyer->id,
            'status'     => 'draft',
        ]);

        $this->withDealer()
            ->actingAs($this->staff)
            ->patchJson("/api/v1/dealer/deals/{$deal->id}/transition", [
                'status' => 'delivered', // Cannot jump from draft to delivered
            ])
            ->assertUnprocessable();
    }

    // ── F&I products ──────────────────────────────────────────────────────

    public function test_dealer_admin_can_sync_fi_products(): void
    {
        $this->dealer->update(['feature_flags' => ['fi_products_enabled' => true]]);

        $deal = Deal::factory()->create([
            'dealer_id'  => $this->dealer->id,
            'vehicle_id' => $this->vehicle->id,
            'buyer_id'   => $this->buyer->id,
            'status'     => 'draft',
        ]);

        $product = FiProduct::factory()->create([
            'dealer_id' => $this->dealer->id,
            'price'     => 100000, // $1,000
            'is_active' => true,
        ]);

        $this->withDealer()
            ->actingAs($this->admin)
            ->putJson("/api/v1/dealer/deals/{$deal->id}/fi-products", [
                'products' => [
                    ['fi_product_id' => $product->id, 'price' => 95000],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.fi_products');
    }

    public function test_fi_products_sync_requires_feature_flag(): void
    {
        // Explicitly disable the fi_products feature flag
        $this->dealer->update(['feature_flags' => ['fi_products_enabled' => false]]);
        $deal = Deal::factory()->create([
            'dealer_id'  => $this->dealer->id,
            'vehicle_id' => $this->vehicle->id,
            'buyer_id'   => $this->buyer->id,
            'status'     => 'draft',
        ]);

        $product = FiProduct::factory()->create([
            'dealer_id' => $this->dealer->id,
            'is_active' => true,
        ]);

        $this->withDealer()
            ->actingAs($this->admin)
            ->putJson("/api/v1/dealer/deals/{$deal->id}/fi-products", [
                'products' => [
                    ['fi_product_id' => $product->id],
                ],
            ])
            ->assertUnprocessable();
    }

    // ── Cancel deal ───────────────────────────────────────────────────────

    public function test_dealer_admin_can_cancel_deal(): void
    {
        $deal = Deal::factory()->create([
            'dealer_id'  => $this->dealer->id,
            'vehicle_id' => $this->vehicle->id,
            'buyer_id'   => $this->buyer->id,
            'status'     => 'draft',
        ]);

        $this->withDealer()
            ->actingAs($this->admin)
            ->deleteJson("/api/v1/dealer/deals/{$deal->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('deals', ['id' => $deal->id]);
    }

    public function test_dealer_staff_cannot_cancel_deal(): void
    {
        $deal = Deal::factory()->create([
            'dealer_id'  => $this->dealer->id,
            'vehicle_id' => $this->vehicle->id,
            'buyer_id'   => $this->buyer->id,
            'status'     => 'draft',
        ]);

        $this->withDealer()
            ->actingAs($this->staff)
            ->deleteJson("/api/v1/dealer/deals/{$deal->id}")
            ->assertForbidden();
    }
}
