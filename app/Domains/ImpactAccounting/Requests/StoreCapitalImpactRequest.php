<?php

namespace App\Domains\ImpactAccounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCapitalImpactRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'activity_name' => ['required', 'string', 'max:255'],
            'impact_type' => ['required', 'string', 'max:255'],
            'impact_direction' => ['required', 'string', 'in:adverse,beneficial,uncertain'],
            'occurred_on' => ['required', 'date'],
            'value' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:100'],
            'source_reference' => ['required', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
