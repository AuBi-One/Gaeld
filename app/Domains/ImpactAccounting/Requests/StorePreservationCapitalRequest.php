<?php

namespace App\Domains\ImpactAccounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePreservationCapitalRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'state_translator' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:100'],
            'threshold' => ['nullable', 'string', 'max:255'],
            'threshold_direction' => ['nullable', 'string', 'in:maximum,minimum,target'],
            'source_reference' => ['required', 'string', 'max:5000'],
        ];
    }
}
