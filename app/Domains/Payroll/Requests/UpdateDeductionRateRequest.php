<?php

namespace App\Domains\Payroll\Requests;

use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeductionRateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();

        return [
            'name' => ['required', 'string', 'max:100'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'type' => ['sometimes', 'string', Rule::in(['employee', 'employer'])],
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('organization_id', $organizationId),
            ],
        ];
    }
}
