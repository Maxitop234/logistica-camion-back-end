<?php

namespace App\Concerns;

/**
 * Reglas para que un punto quede dentro de la zona de operación
 * (Temuco–Padre Las Casas, configurable en `config/ecoruta.php`).
 */
trait ZonaDeOperacionValidationRules
{
    /**
     * @return array<int, string>
     */
    protected function latitudEnZonaRules(bool $obligatoria = true): array
    {
        return [
            $obligatoria ? 'required' : 'sometimes',
            'numeric',
            'between:'.config('ecoruta.zona.latitud_min').','.config('ecoruta.zona.latitud_max'),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function longitudEnZonaRules(bool $obligatoria = true): array
    {
        return [
            $obligatoria ? 'required' : 'sometimes',
            'numeric',
            'between:'.config('ecoruta.zona.longitud_min').','.config('ecoruta.zona.longitud_max'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function zonaDeOperacionMessages(): array
    {
        $zona = (string) config('ecoruta.zona.nombre', 'Temuco–Padre Las Casas');

        return [
            'latitud.between' => "La latitud está fuera de la zona de operación ({$zona}): debe estar entre :min y :max.",
            'longitud.between' => "La longitud está fuera de la zona de operación ({$zona}): debe estar entre :min y :max.",
        ];
    }
}
