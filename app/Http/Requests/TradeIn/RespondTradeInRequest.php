<?php

namespace App\Http\Requests\TradeIn;

use Illuminate\Foundation\Http\FormRequest;

class RespondTradeInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dealer_offer' => ['required', 'integer', 'min:0'],
            'accepted'     => ['sometimes', 'boolean'],
        ];
    }
}
