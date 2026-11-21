<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Registro puntual de telemetría GPS enviado durante una ruta activa.
 *
 * @property int $id
 * @property int $ruta_id
 * @property int $camion_id
 * @property int $chofer_id
 * @property string $ping_uuid
 * @property float $latitud
 * @property float $longitud
 * @property float $velocidad_kmh
 * @property float|null $precision_m
 * @property bool $es_simulado
 * @property Carbon $capturado_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('telemetria_gps')]
#[Fillable([
    'ruta_id',
    'camion_id',
    'chofer_id',
    'ping_uuid',
    'latitud',
    'longitud',
    'velocidad_kmh',
    'precision_m',
    'es_simulado',
    'capturado_en',
])]
class TelemetriaGps extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitud' => 'float',
            'longitud' => 'float',
            'velocidad_kmh' => 'float',
            'precision_m' => 'float',
            'es_simulado' => 'boolean',
            'capturado_en' => 'datetime',
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
     * @return BelongsTo<Camion, $this>
     */
    public function camion(): BelongsTo
    {
        return $this->belongsTo(Camion::class);
    }

    /**
     * @return BelongsTo<Chofer, $this>
     */
    public function chofer(): BelongsTo
    {
        return $this->belongsTo(Chofer::class);
    }
}
