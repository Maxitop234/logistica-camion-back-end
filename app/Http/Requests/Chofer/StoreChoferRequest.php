<?php

namespace App\Http\Requests\Chofer;

use App\Models\Chofer;
use App\Rules\ContrasenaSegura;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChoferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', new ContrasenaSegura],
            'debe_cambiar_password' => ['sometimes', 'boolean'],
            'nombre' => ['required', 'string', 'max:255'],
            'rut' => [
                'required', 'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! Chofer::rutEsValido($value)) {
                        $fail('El RUT no es válido.');
                    } elseif (Chofer::where('rut_hash', Chofer::hashRut($value))->exists()) {
                        $fail('Ya existe un chofer con este RUT.');
                    }
                },
            ],
            'licencia_clase' => ['sometimes', Rule::in(['A1', 'A2', 'A3', 'A4', 'A5', 'B', 'C', 'D', 'E', 'F'])],
            'telefono' => ['nullable', 'string', 'max:20'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }
}
