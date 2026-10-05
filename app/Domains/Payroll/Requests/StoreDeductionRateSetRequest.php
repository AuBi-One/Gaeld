<?php

namespace App\Domains\Payroll\Requests;

use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Payroll\Models\DeductionRateSet;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeductionRateSetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'title' => ['required', 'string', 'max:100'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $organizationId = app(CurrentOrganization::class)->id();
                $overlaps = DeductionRateSet::where('organization_id', $organizationId)
                    ->where('code', $this->input('code'))
                    ->where('date_from', '<=', $this->input('date_to'))
                    ->where('date_to', '>=', $this->input('date_from'))
                    ->exists();

                if ($overlaps) {
                    $validator->errors()->add('date_from', __('validation.deduction_rate_set_overlap'));
                }
            },
        ];
    }
}
