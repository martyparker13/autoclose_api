<?php

namespace App\Http\Requests\CreditApplication;

use Illuminate\Foundation\Http\FormRequest;

class StoreCreditApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dob'                => ['required', 'date', 'before:-18 years'],
            'annual_income'      => ['required', 'integer', 'min:0'],
            'employment_status'  => ['required', 'in:employed,self_employed,retired,unemployed,other'],
            'employer_name'      => ['nullable', 'string', 'max:200'],
            'employer_phone'     => ['nullable', 'string', 'max:20'],
            'monthly_housing'    => ['nullable', 'integer', 'min:0'],
            'housing_status'     => ['nullable', 'in:own,rent,other'],
            'years_at_employer'  => ['nullable', 'integer', 'min:0', 'max:60'],
            'credit_score_range' => ['nullable', 'in:excellent,good,fair,poor,unknown'],
            'ssn'                => ['required', 'string', 'size:9', 'regex:/^\d{9}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'dob.before'  => 'Applicant must be at least 18 years old.',
            'ssn.size'    => 'SSN must be exactly 9 digits (no dashes).',
            'ssn.regex'   => 'SSN must contain digits only.',
        ];
    }
}
