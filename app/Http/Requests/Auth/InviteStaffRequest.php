<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class InviteStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy/role gate enforced at the route level
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role'  => ['required', 'in:dealer_admin,dealer_staff'],
        ];
    }
}
