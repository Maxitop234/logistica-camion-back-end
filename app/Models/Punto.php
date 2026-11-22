<?php

namespace App\Models;

use App\Enums\EstadoLlenado;
use App\Enums\OrigenEstado;
use App\Enums\TipoPunto;
use App\Services\Puntos\VigenciaDeEstado;
use Database\Factories\PuntoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Punto de reciclaje de un sector.
 *
 * `ubicacion` (geography Point 4326) es una columna generada por PostgreSQL a
 * partir de `latitud` y `longitud`: siempre está sincronizada y no se escribe.
 *
 * @property int $id
 * @property string $nombre
 * @property bool $nombre_automatico
 * @property string $direccion
 * @property string $tipo_punto
 * @property int|null $sector_id
 * @property string|null $tipo_material
 * @property string $latitud
 * @property string $longitud
 * @property string $fuente_ubicacion
 * @property bool $requiere_revision
 * @property string|null $nota
 * @property string|null $codigo_externo
 * @property string|null $estado_llenado `null` = nunca medido
 * @property int|null $porcentaje_llenado
 * @property string|null $peso_actual_kg
 * @property Carbon|null $ultima_actualizacion_estado
 * @property string $origen_actualizacion
 * @property Carbon|null $estado_vigente_hasta
 * @property string $altura_contenedor_cm
 * @property string|null $sensor_codigo
 * @property string|null $sensor_token_hash
 * @property string|null $bateria_voltaje
 * @property Carbon|null $ultimo_heartbeat_iot
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Sector|null $sector
 */
#[Table('puntos')]
#[Hidden(['ubicacion'])]
#[Fillable([
    'nombre',
    'nombre_automatico',
    'direccion',
    'tipo_punto',
    'sector_id',
    'tipo_material',
    'latitud',
    'longitud',
    'fuente_ubicacion',
    'requiere_revision',
    'nota',
    'codigo_externo',
    'estado_llenado',
    'porcentaje_llenado',
    'peso_actual_kg',
    'ultima_actualizacion_estado',
    'origen_actualizacion',
    'altura_contenedor_cm',
    'sensor_codigo',
    'sensor_token_hash',
    'bateria_voltaje',
    'ultimo_heartbeat_iot',
    'activo',
])]
class Punto extends Model
{
    /** @use HasFactory<PuntoFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'nombre_automatico' => true,
    ];

    /**
     * El nombre del sector se usa en recursos y KPI: se carga siempre junto al punto.
     *
     * @var list<string>
     */
    protected $with = ['sector'];

    public const TIPO_CONDOMINIO = 'condominio';

    public const TIPO_CALLE = 'calle';

    /** @var array<int, string> */
    public const TIPOS_VALIDOS = [TipoPunto::Condominio->value, TipoPunto::Calle->value];

    /**
     * Etiqueta para agrupar en reportes los puntos que aún no tienen sector.
     */
    public const SIN_SECTOR = 'Sin sector';

    public const ORIGEN_MANUAL = 'manual';

    public const ORIGEN_CHOFER = 'chofer';

    public const ORIGEN_SENSOR_IOT = 'sensor_iot';

    public const ORIGEN_RECOLECCION = 'recoleccion';

    public const FUENTE_COORDENADAS = 'coordenadas';

    public const FUENTE_GEOCODIFICADA = 'geocodificada';

    public const FUENTE_MANUAL = 'manual';

    public const ESTADO_VACIO = 'vacio';

    public const ESTADO_BAJO = 'bajo';

    public const ESTADO_MEDIO = 'medio';

    public const ESTADO_ALTO = 'alto';

    public const ESTADO_LLENO = 'lleno';

    /** @var array<int, string> */
    public const ESTADOS_LISTOS = [self::ESTADO_ALTO, self::ESTADO_LLENO];

    /**
     * Columnas que, al cambiar, obligan a recalcular `estado_vigente_hasta`.
     *
     * @var array<int, string>
     */
    private const CAMPOS_DE_VIGENCIA = [
        'estado_llenado',
        'origen_actualizacion',
        'ultima_actualizacion_estado',
        'sector_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (Punto $punto): void {
            if (! $punto->exists || $punto->isDirty(self::CAMPOS_DE_VIGENCIA)) {
                $punto->estado_vigente_hasta = app(VigenciaDeEstado::class)->calcularVigenteHasta($punto);
            }
        });
    }

    /**
     * Clasifica el porcentaje (0-100) en estado de llenado: 0-10 vacio,
     * 11-40 bajo, 41-70 medio, 71-89 alto, 90-100 lleno.
     */
    public static function clasificarEstado(int $porcentaje): string
    {
        return EstadoLlenado::desdePorcentaje($porcentaje)->value;
    }

    /**
     * Calcula el porcentaje de llenado desde una distancia medida por sensor
     * ultrasónico (JSN-SR04T / HC-SR04) y la altura calibrada del contenedor.
     */
    public static function porcentajeDesdeDistancia(float $distanciaCm, float $alturaContenedorCm): int
    {
        if ($alturaContenedorCm <= 0) {
            return 0;
        }

        $calculado = (($alturaContenedorCm - $distanciaCm) / $alturaContenedorCm) * 100;

        return max(0, min(100, (int) round($calculado)));
    }

    public static function hashSensorToken(string $token): string
    {
        return hash('sha256', trim($token));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nombre_automatico' => 'boolean',
            'porcentaje_llenado' => 'integer',
            'requiere_revision' => 'boolean',
            'altura_contenedor_cm' => 'decimal:2',
            'bateria_voltaje' => 'decimal:2',
            'ultima_actualizacion_estado' => 'datetime',
            'estado_vigente_hasta' => 'datetime',
            'ultimo_heartbeat_iot' => 'datetime',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Sector, $this>
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * @param  Builder<Punto>  $query
     * @return Builder<Punto>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * Puntos activos con estado `alto` o `lleno`.
     *
     * @param  Builder<Punto>  $query
     * @return Builder<Punto>
     */
    public function scopeListos(Builder $query): Builder
    {
        return $query->where('activo', true)->whereIn('estado_llenado', self::ESTADOS_LISTOS);
    }

    /**
     * @param  Builder<Punto>  $query
     * @return Builder<Punto>
     */
    public function scopeDelSector(Builder $query, int $sectorId): Builder
    {
        return $query->where('sector_id', $sectorId);
    }

    /**
     * @param  Builder<Punto>  $query
     * @return Builder<Punto>
     */
    public function scopeListosParaRecolectar(Builder $query): Builder
    {
        return $this->scopeListos($query);
    }

    public function listoParaRecolectar(): bool
    {
        return $this->activo && in_array($this->estado_llenado, self::ESTADOS_LISTOS, true);
    }

    /**
     * Si el estado actual es vigente; si no, el punto cuenta como desconocido (ADR-0013).
     */
    public function estadoVigente(): bool
    {
        return app(VigenciaDeEstado::class)->estaVigente($this);
    }

    public function origenEstado(): ?OrigenEstado
    {
        return OrigenEstado::tryFrom((string) $this->origen_actualizacion);
    }

    public function tieneSensorVinculado(): bool
    {
        return (is_string($this->sensor_token_hash) && $this->sensor_token_hash !== '')
            || (is_string($this->sensor_codigo) && $this->sensor_codigo !== '');
    }

    /**
     * Nombre del sector del punto, o "Sin sector" si aún no tiene uno.
     */
    public function sectorResuelto(): string
    {
        return $this->sector instanceof Sector ? $this->sector->nombre : self::SIN_SECTOR;
    }

    /**
     * @return HasOne<Dispositivo, $this>
     */
    public function dispositivo(): HasOne
    {
        return $this->hasOne(Dispositivo::class);
    }

    /**
     * @return HasMany<LecturaPunto, $this>
     */
    public function lecturas(): HasMany
    {
        return $this->hasMany(LecturaPunto::class)->orderByDesc('medido_en')->orderByDesc('id');
    }
}
