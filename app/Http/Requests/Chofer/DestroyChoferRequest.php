<?php

namespace App\Http\Requests\Chofer;

use Illuminate\Foundation\Http\FormRequest;

class DestroyChoferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['nullable', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.min' => 'El motivo de desactivación debe tener al menos 5 caracteres.',
            'motivo.max' => 'El motivo de desactivación no puede superar los 255 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->motivo)) {
            $limpio = trim($this->motivo);
            $this->merge(['motivo' => $limpio === '' ? null : $limpio]);
        }
    }
}
