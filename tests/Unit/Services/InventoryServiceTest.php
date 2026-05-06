<?php

namespace Tests\Unit\Services;

use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use App\Repositories\VehicleRepositoryInterface;
use App\Services\InventoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $service;

    /** @var \Mockery\MockInterface&VehicleRepositoryInterface */
    private $vehicles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vehicles = Mockery::mock(VehicleRepositoryInterface::class);
        $this->service  = new InventoryService($this->vehicles);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_injects_dealer_id(): void
    {
        $dealer = Dealer::factory()->make(['id' => 1]);

        $this->vehicles
            ->shouldReceive('create')
            ->once()
            ->withArgs(fn ($data) => $data['dealer_id'] === 1)
            ->andReturn(Vehicle::factory()->make(['dealer_id' => 1]));

        $result = $this->service->create($dealer, [
            'year' => 2023, 'make' => 'Toyota', 'model' => 'Camry',
            'mileage' => 0, 'condition' => 'new', 'price' => 3000000,
        ]);

        $this->assertSame(1, $result->dealer_id);
    }

    public function test_update_rejects_different_dealer(): void
    {
        $dealer  = Dealer::factory()->make(['id' => 1]);
        $vehicle = Vehicle::factory()->make(['dealer_id' => 99]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->update($vehicle, $dealer, ['price' => 2500000]);
    }

    public function test_update_calls_repository_when_authorized(): void
    {
        $dealer  = Dealer::factory()->make(['id' => 5]);
        $vehicle = Vehicle::factory()->make(['id' => 10, 'dealer_id' => 5]);

        $this->vehicles
            ->shouldReceive('update')
            ->once()
            ->with($vehicle, ['price' => 2500000])
            ->andReturn($vehicle);

        $result = $this->service->update($vehicle, $dealer, ['price' => 2500000]);

        $this->assertSame($vehicle->id, $result->id);
    }

    public function test_delete_rejects_different_dealer(): void
    {
        $dealer  = Dealer::factory()->make(['id' => 1]);
        $vehicle = Vehicle::factory()->make(['dealer_id' => 99]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->delete($vehicle, $dealer);
    }

    public function test_delete_calls_repository_when_authorized(): void
    {
        $dealer  = Dealer::factory()->make(['id' => 5]);
        $vehicle = Vehicle::factory()->make(['dealer_id' => 5]);

        $this->vehicles
            ->shouldReceive('delete')
            ->once()
            ->with($vehicle);

        $this->service->delete($vehicle, $dealer);

        $this->addToAssertionCount(1); // void method — verify via Mockery expectation
    }
}
