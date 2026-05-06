<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AuthService();
    }

    public function test_register_creates_buyer_user(): void
    {
        $result = $this->service->register([
            'name'     => 'Jane Doe',
            'email'    => 'jane@example.com',
            'password' => 'secret12345',
        ]);

        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('token', $result);
        $this->assertSame('buyer', $result['user']->role);
        $this->assertSame('jane@example.com', $result['user']->email);
        $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'role' => 'buyer']);
    }

    public function test_register_hashes_password(): void
    {
        $result = $this->service->register([
            'name'     => 'Test User',
            'email'    => 'test@example.com',
            'password' => 'plainpassword',
        ]);

        $this->assertTrue(Hash::check('plainpassword', $result['user']->password));
        $this->assertNotSame('plainpassword', $result['user']->password);
    }

    public function test_login_returns_token_for_valid_credentials(): void
    {
        User::factory()->create([
            'email'    => 'buyer@example.com',
            'password' => Hash::make('correctpass'),
        ]);

        $result = $this->service->login([
            'email'    => 'buyer@example.com',
            'password' => 'correctpass',
        ]);

        $this->assertArrayHasKey('token', $result);
        $this->assertNotEmpty($result['token']);
    }

    public function test_login_throws_for_wrong_password(): void
    {
        User::factory()->create([
            'email'    => 'buyer2@example.com',
            'password' => Hash::make('correctpass'),
        ]);

        $this->expectException(AuthenticationException::class);

        $this->service->login([
            'email'    => 'buyer2@example.com',
            'password' => 'wrongpass',
        ]);
    }

    public function test_login_throws_for_unknown_email(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->service->login([
            'email'    => 'nobody@example.com',
            'password' => 'anypass',
        ]);
    }

    public function test_login_revokes_previous_tokens_for_same_device(): void
    {
        $user = User::factory()->create([
            'email'    => 'user@example.com',
            'password' => Hash::make('pass'),
        ]);

        // Create an existing token for this device
        $user->createToken('web');
        $this->assertCount(1, $user->tokens);

        $this->service->login(['email' => 'user@example.com', 'password' => 'pass', 'device_name' => 'web']);

        $user->refresh();
        // Old token revoked, one new token created
        $this->assertCount(1, $user->tokens);
    }

    public function test_update_profile_changes_name(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $updated = $this->service->updateProfile($user, ['name' => 'New Name']);

        $this->assertSame('New Name', $updated->name);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name']);
    }
}
