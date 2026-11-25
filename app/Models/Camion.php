<?php

namespace App\Models;

use Database\Factories\CamionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $patente
 * @property string $marca
 * @property string $modelo
 * @property int $anio
 * @property string $capacidad_kg
 * @property string|null $rendimiento_km_l
 * @property int|null $costo_arriendo_diario
 * @property string $estado_operativo
 * @property int|null $chofer_actual_id
 * @property string|null $motivo_desactivacion
 * @property Carbon|null $desactivado_en
 * @property int|null $desactivado_por_user_id
 * @property string|null $ultima_latitud
 * @property string|null $ultima_longitud
 * @property string $velocidad_actual_kmh
 * @property Carbon|null $ultima_actualizacion_gps
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('camiones')]
#[Fillable([
    'patente', 'marca', 'modelo', 'anio', 'capacidad_kg', 'rendimiento_km_l', 'costo_arriendo_diario',
    'estado_operativo', 'chofer_actual_id',
    'motivo_desactivacion', 'desactivado_en', 'desactivado_por_user_id',
    'ultima_latitud', 'ultima_longitud', 'velocidad_actual_kmh', 'ultima_actualizacion_gps',
])]
class Camion extends Model
{
    /** @use HasFactory<CamionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'capacidad_kg' => 'decimal:2',
            'rendimiento_km_l' => 'decimal:2',
            'costo_arriendo_diario' => 'integer',
            'desactivado_en' => 'datetime',
            'ultima_latitud' => 'decimal:7',
            'ultima_longitud' => 'decimal:7',
            'velocidad_actual_kmh' => 'decimal:2',
            'ultima_actualizacion_gps' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Chofer, $this>
     */
    public function choferActual(): BelongsTo
    {
        return $this->belongsTo(Chofer::class, 'chofer_actual_id');
    }

    /**
     * Ruta planificada o en curso que bloquea al camión (la más reciente).
     *
     * @return HasOne<Ruta, $this>
     */
    public function rutaActiva(): HasOne
    {
        return $this->hasOne(Ruta::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->whereIn('estado', Ruta::ESTADOS_ACTIVOS),
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function desactivadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'desactivado_por_user_id');
    }
}
