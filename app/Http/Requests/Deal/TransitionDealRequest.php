<?php

namespace App\Http\Requests\Deal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([
                    'draft',
                    'credit_submitted',
                    'credit_approved',
                    'credit_declined',
                    'docs_pending',
                    'docs_signed',
                    'awaiting_delivery',
                    'delivered',
                    'cancelled',
                ]),
            ],
        ];
    }
}
