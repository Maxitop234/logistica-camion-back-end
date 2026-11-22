<?php

namespace App\Http\Requests\Punto;

use App\Enums\EstadoLlenado;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros de `GET /puntos`. Las listas se separan por comas.
 */
class ListarPuntosRequest extends FormRequest
{
    /**
     * Valor del filtro `estado_llenado` para los puntos nunca medidos.
     */
    public const SIN_MEDICION = 'sin_medicion';

    private const BOOLEANOS = 'in:0,1,true,false';

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
            'estado_llenado' => ['sometimes', 'string', $this->listaDe([...EstadoLlenado::valores(), self::SIN_MEDICION])],
            'sector_id' => ['sometimes', 'string', $this->listaDeEnteros()],
            'activo' => ['sometimes', self::BOOLEANOS],
            'solo_activos' => ['sometimes', self::BOOLEANOS],
            'con_dispositivo' => ['sometimes', self::BOOLEANOS],
            'requiere_revision' => ['sometimes', self::BOOLEANOS],
            'estado_vigente' => ['sometimes', self::BOOLEANOS],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function estados(): array
    {
        return $this->separar((string) $this->query('estado_llenado', ''));
    }

    /**
     * @return array<int, int>
     */
    public function sectorIds(): array
    {
        return array_map('intval', $this->separar((string) $this->query('sector_id', '')));
    }

    /**
     * @param  array<int, string>  $permitidos
     */
    private function listaDe(array $permitidos): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($permitidos): void {
            $invalidos = array_diff($this->separar((string) $value), $permitidos);
            if ($invalidos !== []) {
                $fail('Valores no válidos en :attribute: '.implode(', ', $invalidos).'. Usa: '.implode(', ', $permitidos).'.');
            }
        };
    }

    private function listaDeEnteros(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            foreach ($this->separar((string) $value) as $parte) {
                if (! ctype_digit($parte)) {
                    $fail('El filtro :attribute debe ser una lista de ids separados por comas.');

                    return;
                }
            }
        };
    }

    /**
     * @return array<int, string>
     */
    private function separar(string $valor): array
    {
        return array_values(array_filter(
            array_map(static fn (string $parte): string => trim($parte), explode(',', $valor)),
            static fn (string $parte): bool => $parte !== '',
        ));
    }
}
