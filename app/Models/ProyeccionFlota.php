<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Historial diario de proyección de flota necesaria (KPI 9) y su acierto real.
 *
 * @property int $id
 * @property Carbon $fecha_objetivo
 * @property int $puntos_proyectados
 * @property int $camiones_proyectados
 * @property int|null $puntos_reales_listos
 * @property string|null $porcentaje_acierto
 * @property Carbon $calculada_en
 * @property Carbon|null $evaluada_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('proyecciones_flota')]
#[Fillable([
    'fecha_objetivo',
    'puntos_proyectados',
    'camiones_proyectados',
    'puntos_reales_listos',
    'porcentaje_acierto',
    'calculada_en',
    'evaluada_en',
])]
class ProyeccionFlota extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_objetivo' => 'date',
            'puntos_proyectados' => 'integer',
            'camiones_proyectados' => 'integer',
            'puntos_reales_listos' => 'integer',
            'porcentaje_acierto' => 'decimal:2',
            'calculada_en' => 'datetime',
            'evaluada_en' => 'datetime',
        ];
    }
}
