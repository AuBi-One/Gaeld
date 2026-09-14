<?php

namespace App\Domains\ImpactAccounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCapitalObservationRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'observed_on' => ['required', 'date'],
            'value' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:100'],
            'source_reference' => ['required', 'string', 'max:5000'],
            'confidence' => ['nullable', 'string', 'in:measured,estimated,professional,judgment'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
