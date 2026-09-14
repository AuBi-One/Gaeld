<?php

namespace App\Domains\ImpactAccounting\Requests;

use App\Domains\ImpactAccounting\Enums\PreservationActionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePreservationActionRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'action_type' => ['required', Rule::enum(PreservationActionType::class)],
            'status' => ['required', 'string', 'in:planned,in_progress,completed,cancelled'],
            'due_on' => ['nullable', 'date'],
            'source_reference' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
