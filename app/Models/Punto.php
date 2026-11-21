<?php

namespace App\Models;

use Database\Factories\PuntoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $nombre
 * @property bool $nombre_automatico
 * @property string $direccion
 * @property string $tipo_punto
 * @property string|null $sector
 * @property string $latitud
 * @property string $longitud
 * @property string $estado_llenado
 * @property int $porcentaje_llenado
 * @property string|null $peso_actual_kg
 * @property Carbon|null $ultima_actualizacion_estado
 * @property string $origen_actualizacion
 * @property string $altura_contenedor_cm
 * @property string|null $sensor_codigo
 * @property string|null $sensor_token_hash
 * @property string|null $bateria_voltaje
 * @property Carbon|null $ultimo_heartbeat_iot
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('puntos')]
#[Fillable([
    'nombre',
    'nombre_automatico',
    'direccion',
    'tipo_punto',
    'sector',
    'latitud',
    'longitud',
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

    public const TIPO_CONDOMINIO = 'condominio';

    public const TIPO_CALLE = 'calle';

    /** @var array<int, string> */
    public const TIPOS_VALIDOS = [self::TIPO_CONDOMINIO, self::TIPO_CALLE];

    public const SECTOR_CENTRO = 'Centro';

    public const SECTOR_PONIENTE = 'Poniente';

    public const SECTOR_ORIENTE = 'Oriente';

    public const SECTOR_NORTE = 'Norte';

    public const SECTOR_PADRE_LAS_CASAS = 'Padre Las Casas';

    /** @var array<int, string> */
    public const SECTORES_VALIDOS = [
        self::SECTOR_CENTRO,
        self::SECTOR_PONIENTE,
        self::SECTOR_ORIENTE,
        self::SECTOR_NORTE,
        self::SECTOR_PADRE_LAS_CASAS,
    ];

    public const ORIGEN_MANUAL = 'manual';

    public const ORIGEN_CHOFER = 'chofer';

    public const ORIGEN_SENSOR_IOT = 'sensor_iot';

    public const ESTADO_VACIO = 'vacio';

    public const ESTADO_BAJO = 'bajo';

    public const ESTADO_MEDIO = 'medio';

    public const ESTADO_ALTO = 'alto';

    public const ESTADO_LLENO = 'lleno';

    /** @var array<int, string> */
    public const ESTADOS_LISTOS = [self::ESTADO_ALTO, self::ESTADO_LLENO];

    /**
     * Clasifica el porcentaje (0-100) en estado de llenado consistente con el FE:
     * 0-10 vacio, 11-40 bajo, 41-70 medio, 71-89 alto, 90-100 lleno.
     */
    public static function clasificarEstado(int $porcentaje): string
    {
        $p = max(0, min(100, $porcentaje));

        return match (true) {
            $p <= 10 => self::ESTADO_VACIO,
            $p <= 40 => self::ESTADO_BAJO,
            $p <= 70 => self::ESTADO_MEDIO,
            $p <= 89 => self::ESTADO_ALTO,
            default => self::ESTADO_LLENO,
        };
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
            'altura_contenedor_cm' => 'decimal:2',
            'bateria_voltaje' => 'decimal:2',
            'ultima_actualizacion_estado' => 'datetime',
            'ultimo_heartbeat_iot' => 'datetime',
            'activo' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Punto>  $query
     * @return Builder<Punto>
     */
    public function scopeListosParaRecolectar(Builder $query): Builder
    {
        return $query->where('activo', true)->whereIn('estado_llenado', self::ESTADOS_LISTOS);
    }

    public function listoParaRecolectar(): bool
    {
        return $this->activo && in_array($this->estado_llenado, self::ESTADOS_LISTOS, true);
    }

    public function tieneSensorVinculado(): bool
    {
        return (is_string($this->sensor_token_hash) && $this->sensor_token_hash !== '')
            || (is_string($this->sensor_codigo) && $this->sensor_codigo !== '');
    }

    /**
     * Devuelve el sector explícito si pertenece a la lista cerrada o, en su
     * defecto, lo clasifica automáticamente por cuadrante geográfico en
     * Temuco / Padre Las Casas.
     */
    public function sectorResuelto(): string
    {
        if (is_string($this->sector) && in_array($this->sector, self::SECTORES_VALIDOS, true)) {
            return $this->sector;
        }

        return self::clasificarSectorPorCoordenada((float) $this->latitud, (float) $this->longitud);
    }

    public static function clasificarSectorPorCoordenada(float $lat, float $lon): string
    {
        // Al sur del río Cautín: Padre Las Casas.
        if ($lat <= -38.7580) {
            return self::SECTOR_PADRE_LAS_CASAS;
        }
        if ($lat >= -38.7240) {
            return self::SECTOR_NORTE;
        }
        if ($lon <= -72.6020) {
            return self::SECTOR_PONIENTE;
        }
        if ($lon >= -72.5800) {
            return self::SECTOR_ORIENTE;
        }

        return self::SECTOR_CENTRO;
    }

    /**
     * @return HasMany<LecturaPunto, $this>
     */
    public function lecturas(): HasMany
    {
        return $this->hasMany(LecturaPunto::class)->orderByDesc('medido_en')->orderByDesc('id');
    }
}
