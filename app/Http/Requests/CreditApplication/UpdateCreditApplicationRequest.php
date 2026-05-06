<?php

namespace App\Http\Requests\CreditApplication;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCreditApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision'       => ['sometimes', 'in:pending,approved,declined,conditional'],
            'approved_amount'=> ['nullable', 'integer', 'min:0'],
            'approved_apr'   => ['nullable', 'numeric', 'min:0', 'max:99.999'],
            'approved_term'  => ['nullable', 'integer', 'min:1', 'max:240'],
        ];
    }
}
