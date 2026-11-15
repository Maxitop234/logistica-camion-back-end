<?php

namespace App\Models;

use Database\Factories\CamionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $patente
 * @property string $marca
 * @property string $modelo
 * @property int $anio
 * @property string $capacidad_kg
 * @property string $estado_operativo
 * @property int|null $chofer_actual_id
 * @property string|null $ultima_latitud
 * @property string|null $ultima_longitud
 * @property string $velocidad_actual_kmh
 * @property Carbon|null $ultima_actualizacion_gps
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('camiones')]
#[Fillable([
    'patente', 'marca', 'modelo', 'anio', 'capacidad_kg', 'estado_operativo', 'chofer_actual_id',
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
}
