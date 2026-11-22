<?php

namespace App\Http\Requests\Desvio;

use App\Models\DesvioRuta;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarMotivoDesvioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El motivo es siempre opcional: el chofer puede informar uno o dejar el desvío como revisado ("omitir").
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'motivo_codigo' => ['nullable', 'string', Rule::in(DesvioRuta::motivosValidos())],
            'motivo_texto' => ['nullable', 'string', 'max:300'],
            'omitir' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->filled('motivo_codigo') && ! $this->filled('motivo_texto') && ! $this->boolean('omitir')) {
                $v->errors()->add('motivo_codigo', 'Indica un motivo o marca el desvío como omitido.');
            }
        });
    }
}
