<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthService
{
    /**
     * Register a new buyer account.
     *
     * @param  array<string, mixed>  $data
     * @throws \Illuminate\Validation\ValidationException
     */
    public function register(array $data): array
    {
        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => $data['password'],
            'role'      => 'buyer',
            'phone'     => $data['phone'] ?? null,
        ]);

        $token = $user->createToken($data['device_name'] ?? 'web')->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }

    /**
     * Authenticate a user and return a Sanctum token.
     *
     * @param  array<string, mixed>  $credentials
     * @throws AuthenticationException
     */
    public function login(array $credentials): array
    {
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('These credentials do not match our records.');
        }

        // Revoke previous tokens for the same device to avoid accumulation
        $deviceName = $credentials['device_name'] ?? 'web';
        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createToken($deviceName)->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }

    /**
     * Revoke the current access token.
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }
    }

    /**
     * Update the authenticated user's profile.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        $user->update(array_filter([
            'name'   => $data['name'] ?? null,
            'phone'  => $data['phone'] ?? null,
        ], fn ($v) => $v !== null));

        return $user->fresh();
    }

    /**
     * Send a password-reset link to the given email address.
     *
     * @return string  One of the Password::* broker status constants
     */
    public function sendResetLink(string $email): string
    {
        return Password::sendResetLink(['email' => $email]);
    }

    /**
     * Reset the user's password using the broker token.
     *
     * @param  array<string, mixed>  $credentials  Requires: token, email, password, password_confirmation
     * @return string  One of the Password::* broker status constants
     */
    public function resetPassword(array $credentials): string
    {
        return Password::reset($credentials, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password)])->save();
            $user->tokens()->delete(); // revoke all tokens on password reset
        });
    }

    /**
     * Create a dealer staff/admin account and send a password-reset (set-password) email.
     *
     * @param  array<string, mixed>  $data  Requires: name, email, role
     * @param  int  $dealerId
     */
    public function inviteStaff(array $data, int $dealerId): User
    {
        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'role'      => $data['role'],
            'dealer_id' => $dealerId,
            'password'  => Hash::make(Str::random(32)), // random; reset required
        ]);

        // Send set-password email via the standard broker
        Password::sendResetLink(['email' => $user->email]);

        return $user;
    }
}
