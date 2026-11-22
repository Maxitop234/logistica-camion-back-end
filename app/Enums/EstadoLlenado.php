<?php

namespace App\Enums;

/**
 * Nivel de llenado de un punto de reciclaje.
 *
 * Es la única implementación de los umbrales (10/40/70/89). Un punto nunca
 * medido no tiene estado (`null`): nunca se trata como `Vacio` (ADR-0013).
 */
enum EstadoLlenado: string
{
    case Vacio = 'vacio';
    case Bajo = 'bajo';
    case Medio = 'medio';
    case Alto = 'alto';
    case Lleno = 'lleno';

    /**
     * Clasifica un porcentaje (se acota a 0–100): 0–10 vacío, 11–40 bajo,
     * 41–70 medio, 71–89 alto y 90–100 lleno.
     */
    public static function desdePorcentaje(int $porcentaje): self
    {
        $acotado = max(0, min(100, $porcentaje));

        return match (true) {
            $acotado <= 10 => self::Vacio,
            $acotado <= 40 => self::Bajo,
            $acotado <= 70 => self::Medio,
            $acotado <= 89 => self::Alto,
            default => self::Lleno,
        };
    }

    /**
     * Con estado vigente, solo `alto` y `lleno` entran en la ruta.
     */
    public function estaListo(): bool
    {
        return $this === self::Alto || $this === self::Lleno;
    }

    /**
     * @return array<int, string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $estado): string => $estado->value, self::cases());
    }
}
