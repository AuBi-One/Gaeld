<?php

namespace App\Domains\ImpactAccounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationActivityRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:5000'],
            'inputs' => ['nullable', 'string', 'max:5000'],
            'outputs' => ['nullable', 'string', 'max:5000'],
            'source_reference' => ['required', 'string', 'max:5000'],
        ];
    }
}
