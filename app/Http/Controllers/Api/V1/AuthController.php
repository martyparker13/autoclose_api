<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\InviteStaffRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\ActivityLog;
use App\Services\AuthService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class AuthController extends BaseController
{
    public function __construct(private readonly AuthService $authService) {}

    /**
     * Register a new buyer account.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return response()->json([
            'data'  => new UserResource($result['user']),
            'token' => $result['token'],
        ], 201);
    }

    /**
     * Authenticate and return a token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login($request->validated());
        } catch (AuthenticationException $e) {
            return $this->errorResponse($e->getMessage(), 401);
        }

        $user = $result['user']->load('dealer');

        ActivityLog::record('user.login', $user);

        return response()->json([
            'data'  => new UserResource($user),
            'token' => $result['token'],
        ]);
    }

    /**
     * Revoke the current token (logout).
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        ActivityLog::record('user.logout', $user);
        $this->authService->logout($user);

        return $this->noContent();
    }

    /**
     * Return the authenticated user's profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('dealer');

        return response()->json(['data' => new UserResource($user)]);
    }

    /**
     * Update the authenticated user's profile.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $request->validate([
            'name'  => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $user = $this->authService->updateProfile($request->user(), $request->validated());

        return response()->json(['data' => new UserResource($user)]);
    }

    /**
     * Send a password-reset link email.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = $this->authService->sendResetLink($request->validated('email'));

        if ($status !== Password::RESET_LINK_SENT) {
            return $this->errorResponse(__($status), 422);
        }

        return response()->json(['message' => __($status)]);
    }

    /**
     * Reset the user's password using the broker token.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = $this->authService->resetPassword($request->validated());

        if ($status !== Password::PASSWORD_RESET) {
            return $this->errorResponse(__($status), 422);
        }

        return response()->json(['message' => __($status)]);
    }

    /**
     * Invite a new dealer staff or admin user.
     * Only accessible by dealer_admin role.
     */
    public function inviteStaff(InviteStaffRequest $request): JsonResponse
    {
        $dealer = app('current_dealer');
        $user   = $this->authService->inviteStaff($request->validated(), $dealer->id);

        ActivityLog::record('staff.invited', $user, [], ['email' => $user->email, 'role' => $user->role]);

        return response()->json(['data' => new UserResource($user)], 201);
    }

    /**
     * List all staff members for the current dealer.
     * Only accessible by dealer_admin role.
     */
    public function listStaff(Request $request): JsonResponse
    {
        $dealer = app('current_dealer');

        $staff = \App\Models\User::where('dealer_id', $dealer->id)
            ->whereIn('role', ['dealer_admin', 'dealer_staff'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => UserResource::collection($staff)]);
    }
}
