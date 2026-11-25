<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $camion_id
 * @property int $chofer_id
 * @property int|null $creado_por_admin_id
 * @property Carbon $fecha_programada
 * @property string $estado
 * @property int $total_puntos_evaluados
 * @property int $puntos_incluidos_count
 * @property int $puntos_excluidos_count
 * @property string $distancia_estimada_km
 * @property string $distancia_real_km
 * @property int $duracion_estimada_min
 * @property string|null $encoded_polyline
 * @property Carbon|null $hora_inicio_real
 * @property Carbon|null $hora_termino_real
 * @property string|null $motivo_cancelacion
 * @property Carbon|null $cancelada_en
 * @property int|null $cancelada_por_admin_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('rutas')]
#[Fillable([
    'camion_id',
    'chofer_id',
    'creado_por_admin_id',
    'fecha_programada',
    'estado',
    'total_puntos_evaluados',
    'puntos_incluidos_count',
    'puntos_excluidos_count',
    'distancia_estimada_km',
    'distancia_real_km',
    'duracion_estimada_min',
    'encoded_polyline',
    'hora_inicio_real',
    'hora_termino_real',
    'motivo_cancelacion',
    'cancelada_en',
    'cancelada_por_admin_id',
])]
class Ruta extends Model
{
    public const ESTADO_PLANIFICADA = 'planificada';

    public const ESTADO_EN_CURSO = 'en_curso';

    public const ESTADO_COMPLETADA = 'completada';

    public const ESTADO_CANCELADA = 'cancelada';

    /**
     * Estados que mantienen ocupados al chofer y al camión de la ruta.
     *
     * @var array<int, string>
     */
    public const ESTADOS_ACTIVOS = [self::ESTADO_PLANIFICADA, self::ESTADO_EN_CURSO];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_programada' => 'date',
            'total_puntos_evaluados' => 'integer',
            'puntos_incluidos_count' => 'integer',
            'puntos_excluidos_count' => 'integer',
            'distancia_real_km' => 'decimal:2',
            'duracion_estimada_min' => 'integer',
            'hora_inicio_real' => 'datetime',
            'hora_termino_real' => 'datetime',
            'cancelada_en' => 'datetime',
        ];
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

    /**
     * @return BelongsTo<Administrador, $this>
     */
    public function creadoPorAdmin(): BelongsTo
    {
        return $this->belongsTo(Administrador::class, 'creado_por_admin_id');
    }

    /**
     * @return HasMany<RutaPunto, $this>
     */
    public function paradas(): HasMany
    {
        return $this->hasMany(RutaPunto::class)->orderBy('orden_parada');
    }

    /**
     * @return HasMany<TelemetriaGps, $this>
     */
    public function telemetriaGps(): HasMany
    {
        return $this->hasMany(TelemetriaGps::class)->orderBy('capturado_en')->orderBy('id');
    }
}
