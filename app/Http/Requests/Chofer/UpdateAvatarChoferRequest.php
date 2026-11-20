<?php

namespace App\Http\Requests\Chofer;

use App\Models\Chofer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAvatarChoferRequest extends FormRequest
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
            'peinado' => ['required', 'string', Rule::in(Chofer::AVATAR_PEINADOS)],
            'color_pelo' => ['required', 'string', Rule::in(Chofer::AVATAR_COLORES_PELO)],
            'tono_piel' => ['required', 'string', Rule::in(Chofer::AVATAR_TONOS_PIEL)],
            'lentes' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'peinado.in' => 'El peinado seleccionado no es válido.',
            'color_pelo.in' => 'El color de pelo seleccionado no es válido.',
            'tono_piel.in' => 'El tono de piel seleccionado no es válido.',
            'lentes.boolean' => 'El campo lentes debe ser verdadero o falso.',
        ];
    }
}
