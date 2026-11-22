<?php

namespace App\Models;

use Database\Factories\DispositivoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Nodo sensor (ESP32 + ultrasónico) asociado, a lo más, a un punto.
 *
 * El token propio se guarda solo como hash. El token en claro existe cifrado
 * únicamente entre la aprobación y su entrega al sensor (una sola vez).
 *
 * @property int $id
 * @property int|null $punto_id
 * @property string $codigo_hardware
 * @property string $tipo_sensor
 * @property string $conectividad
 * @property string|null $firmware_version
 * @property string $altura_contenedor_cm
 * @property string $distancia_lleno_cm
 * @property int $intervalo_lectura_seg
 * @property string $estado
 * @property string|null $token_hash
 * @property string|null $token_pendiente_cifrado
 * @property Carbon|null $token_entregado_en
 * @property Carbon|null $anunciado_en
 * @property Carbon|null $vinculado_en
 * @property int|null $aprobado_por_user_id
 * @property Carbon|null $ultima_conexion_en
 * @property Carbon|null $ultima_lectura_en
 * @property string|null $ultima_distancia_cm
 * @property string|null $bateria_voltaje
 * @property int|null $rssi_dbm
 * @property int $lecturas_invalidas_seguidas
 * @property bool $es_simulado
 * @property array<string, mixed>|null $simulacion
 * @property string|null $notas
 * @property-read Punto|null $punto
 */
#[Table('dispositivos')]
#[Hidden(['token_hash', 'token_pendiente_cifrado', 'simulacion'])]
#[Fillable([
    'punto_id',
    'codigo_hardware',
    'tipo_sensor',
    'conectividad',
    'firmware_version',
    'altura_contenedor_cm',
    'distancia_lleno_cm',
    'intervalo_lectura_seg',
    'estado',
    'token_hash',
    'token_pendiente_cifrado',
    'token_entregado_en',
    'anunciado_en',
    'vinculado_en',
    'aprobado_por_user_id',
    'ultima_conexion_en',
    'ultima_lectura_en',
    'ultima_distancia_cm',
    'bateria_voltaje',
    'rssi_dbm',
    'lecturas_invalidas_seguidas',
    'es_simulado',
    'simulacion',
    'notas',
])]
class Dispositivo extends Model
{
    /** @use HasFactory<DispositivoFactory> */
    use HasFactory;

    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_ACTIVO = 'activo';

    public const ESTADO_DESACTIVADO = 'desactivado';

    public const TIPO_HC_SR04 = 'hc_sr04';

    public const TIPO_JSN_SR04T = 'jsn_sr04t';

    /** @var array<int, string> */
    public const TIPOS_SENSOR = [self::TIPO_HC_SR04, self::TIPO_JSN_SR04T];

    /** @var array<int, string> */
    public const CONECTIVIDADES = ['wifi', 'lte_m', 'nb_iot', 'lorawan'];

    public const INVALIDA_SIN_ECO = 'sin_eco';

    public const INVALIDA_ZONA_CIEGA = 'zona_ciega';

    public const INVALIDA_FUERA_DE_RANGO = 'fuera_de_rango';

    public const INVALIDA_ERROR_SENSOR = 'error_sensor';

    /** @var array<string, mixed> */
    protected $attributes = [
        'estado' => self::ESTADO_PENDIENTE,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'altura_contenedor_cm' => 'decimal:2',
            'distancia_lleno_cm' => 'decimal:2',
            'intervalo_lectura_seg' => 'integer',
            'ultima_distancia_cm' => 'decimal:2',
            'bateria_voltaje' => 'decimal:2',
            'rssi_dbm' => 'integer',
            'lecturas_invalidas_seguidas' => 'integer',
            'es_simulado' => 'boolean',
            'simulacion' => 'encrypted:array',
            'token_pendiente_cifrado' => 'encrypted',
            'token_entregado_en' => 'datetime',
            'anunciado_en' => 'datetime',
            'vinculado_en' => 'datetime',
            'ultima_conexion_en' => 'datetime',
            'ultima_lectura_en' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', trim($token));
    }

    /**
     * Token aleatorio de 48 caracteres con prefijo reconocible (ADR-0012).
     */
    public static function nuevoToken(): string
    {
        return 'eco_iot_'.Str::random(48);
    }

    /**
     * Zona ciega del modelo de sensor, en cm (lecturas más cortas son imposibles).
     */
    public function zonaCiegaCm(): float
    {
        return (float) config("iot.sensores.{$this->tipo_sensor}.zona_ciega_cm", 2.0);
    }

    public function alcanceMaximoCm(): float
    {
        return (float) config("iot.sensores.{$this->tipo_sensor}.alcance_cm", 400.0);
    }

    /**
     * Evalúa una distancia medida con la calibración del dispositivo.
     *
     * Una lectura imposible (sin eco, dentro de la zona ciega, más lejos que el
     * alcance o que el fondo calibrado) es inválida y nunca cuenta como 100 %.
     *
     * @return array{valida: bool, porcentaje: int|null, motivo: string|null}
     */
    public function evaluarDistancia(?float $distanciaCm): array
    {
        $invalida = static fn (string $motivo): array => ['valida' => false, 'porcentaje' => null, 'motivo' => $motivo];

        if ($distanciaCm === null || $distanciaCm <= 0) {
            return $invalida(self::INVALIDA_SIN_ECO);
        }

        if ($distanciaCm < $this->zonaCiegaCm()) {
            return $invalida(self::INVALIDA_ZONA_CIEGA);
        }

        $altura = (float) $this->altura_contenedor_cm;
        if ($distanciaCm > $this->alcanceMaximoCm()
            || $distanciaCm > $altura + (float) config('iot.tolerancia_fondo_cm', 10)) {
            return $invalida(self::INVALIDA_FUERA_DE_RANGO);
        }

        $lleno = (float) $this->distancia_lleno_cm;
        $rango = $altura - $lleno;
        if ($rango <= 0) {
            return $invalida(self::INVALIDA_ERROR_SENSOR);
        }

        $porcentaje = (int) round((($altura - $distanciaCm) / $rango) * 100);

        return ['valida' => true, 'porcentaje' => max(0, min(100, $porcentaje)), 'motivo' => null];
    }

    /**
     * Porcentaje de batería estimado de una celda Li-ion/LiPo (3,2 V = 0 %, 4,2 V = 100 %).
     */
    public function bateriaPorcentaje(): ?int
    {
        if ($this->bateria_voltaje === null) {
            return null;
        }

        return max(0, min(100, (int) round(((float) $this->bateria_voltaje - 3.2) / 1.0 * 100)));
    }

    public function bateriaBaja(): bool
    {
        return $this->bateria_voltaje !== null
            && (float) $this->bateria_voltaje < (float) config('iot.bateria_minima_v', 3.40);
    }

    /**
     * Minutos desde la última conexión del sensor (null si nunca se conectó).
     */
    public function minutosSinReportar(): ?int
    {
        $ultima = $this->ultima_conexion_en ?? $this->ultima_lectura_en;

        return $ultima === null ? null : max(0, (int) $ultima->diffInMinutes(now(), true));
    }

    /**
     * Aún no ha enviado ninguna lectura propia (válida ni inválida); el anuncio y la
     * consulta de estado solo cuentan como conexión.
     */
    public function sinLecturas(): bool
    {
        return $this->ultima_lectura_en === null;
    }

    /**
     * Más de N intervalos sin conexión (N = `iot.intervalos_sin_reporte`).
     */
    public function sinReportar(): bool
    {
        if ($this->estado !== self::ESTADO_ACTIVO) {
            return false;
        }

        $minutos = $this->minutosSinReportar();
        if ($minutos === null) {
            // Aprobado y aún sin ninguna lectura: cuenta desde que se vinculó.
            $minutos = $this->vinculado_en === null ? null : max(0, (int) $this->vinculado_en->diffInMinutes(now(), true));
        }

        $limite = ($this->intervalo_lectura_seg * max(1, (int) config('iot.intervalos_sin_reporte', 2))) / 60;

        return $minutos !== null && $minutos > $limite;
    }

    /**
     * Estado que ve el administrador: pendiente, desactivado, sin_reportar,
     * esperando_lectura, bateria_baja o en_linea.
     */
    public function estadoConexion(): string
    {
        return match (true) {
            $this->estado === self::ESTADO_PENDIENTE => 'pendiente',
            $this->estado === self::ESTADO_DESACTIVADO => 'desactivado',
            $this->sinReportar() => 'sin_reportar',
            $this->sinLecturas() => 'esperando_lectura',
            $this->bateriaBaja() => 'bateria_baja',
            default => 'en_linea',
        };
    }

    /**
     * @return BelongsTo<Punto, $this>
     */
    public function punto(): BelongsTo
    {
        return $this->belongsTo(Punto::class);
    }

    /**
     * @return HasMany<LecturaPunto, $this>
     */
    public function lecturas(): HasMany
    {
        return $this->hasMany(LecturaPunto::class)->orderByDesc('medido_en')->orderByDesc('id');
    }

    /**
     * @return HasMany<LecturaInvalida, $this>
     */
    public function lecturasInvalidas(): HasMany
    {
        return $this->hasMany(LecturaInvalida::class)->orderByDesc('medido_en')->orderByDesc('id');
    }
}
