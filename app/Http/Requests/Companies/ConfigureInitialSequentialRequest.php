<?php

namespace App\Http\Requests\Companies;

use Illuminate\Foundation\Http\FormRequest;

final class ConfigureInitialSequentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'initialSequential' => ['required', 'integer', 'min:1', 'max:999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'initialSequential.required' => 'Escribe el primer secuencial.',
            'initialSequential.integer' => 'El secuencial sólo puede contener números.',
            'initialSequential.min' => 'El secuencial debe ser mayor que cero.',
            'initialSequential.max' => 'El secuencial no puede superar 999999999.',
        ];
    }
}
