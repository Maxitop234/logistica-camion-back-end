<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class DesafioDosPasosRequest extends FormRequest
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
            'desafio' => ['required', 'string', 'max:100'],
            'codigo' => ['nullable', 'string', 'max:12', 'required_without:codigo_recuperacion'],
            'codigo_recuperacion' => ['nullable', 'string', 'max:40', 'required_without:codigo'],
        ];
    }
}
