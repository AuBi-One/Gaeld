<?php

namespace App\Domains\Payroll\Requests;

use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeductionRateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();

        return [
            'deduction_rate_set_id' => [
                'required',
                'integer',
                Rule::exists('deduction_rate_sets', 'id')->where('organization_id', $organizationId),
            ],
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('deduction_rates')
                    ->where('organization_id', $organizationId)
                    ->where('deduction_rate_set_id', $this->input('deduction_rate_set_id')),
            ],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'type' => ['required', 'string', Rule::in(['employee', 'employer'])],
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('organization_id', $organizationId),
            ],
        ];
    }
}
