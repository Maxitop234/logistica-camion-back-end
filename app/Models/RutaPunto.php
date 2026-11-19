<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ruta_id
 * @property int $punto_id
 * @property int $orden_parada
 * @property string $estado_llenado_inicial
 * @property string $estado_parada
 * @property string|null $distancia_tramo_km
 * @property int|null $duracion_tramo_min
 * @property Carbon|null $hora_estimada_llegada
 * @property Carbon|null $hora_real_recoleccion
 * @property string|null $sync_uuid
 * @property bool $sincronizado_offline
 * @property string|null $observaciones
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('ruta_puntos')]
#[Fillable([
    'ruta_id',
    'punto_id',
    'orden_parada',
    'estado_llenado_inicial',
    'estado_parada',
    'distancia_tramo_km',
    'duracion_tramo_min',
    'hora_estimada_llegada',
    'hora_real_recoleccion',
    'sync_uuid',
    'sincronizado_offline',
    'observaciones',
])]
class RutaPunto extends Model
{
    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_RECOLECTADO = 'recolectado';

    public const ESTADO_OMITIDO = 'omitido';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orden_parada' => 'integer',
            'duracion_tramo_min' => 'integer',
            'hora_estimada_llegada' => 'datetime',
            'hora_real_recoleccion' => 'datetime',
            'sincronizado_offline' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Ruta, $this>
     */
    public function ruta(): BelongsTo
    {
        return $this->belongsTo(Ruta::class);
    }

    /**
     * @return BelongsTo<Punto, $this>
     */
    public function punto(): BelongsTo
    {
        return $this->belongsTo(Punto::class);
    }
}
