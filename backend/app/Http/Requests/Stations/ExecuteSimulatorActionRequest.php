<?php

namespace App\Http\Requests\Stations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExecuteSimulatorActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in([
                'connect', 'disconnect', 'heartbeat',
                'plug', 'unplug', 'inject_fault', 'recover',
                'normal_cycle', 'fault_recovery',
            ])],
            'connector_id' => [
                Rule::requiredIf(fn (): bool => in_array((string) $this->input('action'), [
                    'plug', 'unplug', 'inject_fault', 'recover', 'normal_cycle', 'fault_recovery',
                ], true)),
                'nullable',
                'integer',
                'min:1',
            ],
        ];
    }
}
