<?php

namespace App\Http\Requests\Kpi;

use App\Models\ParametroOperativo;
use Illuminate\Foundation\Http\FormRequest;

class UpdateParametrosRequest extends FormRequest
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
        $rules = [];
        foreach (ParametroOperativo::DEFINICIONES as $clave => $def) {
            $rules[$clave] = ['sometimes', 'numeric', "min:{$def['min']}", "max:{$def['max']}"];
        }

        return $rules;
    }
}
