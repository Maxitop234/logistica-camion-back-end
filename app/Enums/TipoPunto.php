<?php

namespace App\Enums;

/**
 * Tipo de punto de reciclaje.
 */
enum TipoPunto: string
{
    case Condominio = 'condominio';
    case Calle = 'calle';

    /**
     * @return array<int, string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $tipo): string => $tipo->value, self::cases());
    }
}
