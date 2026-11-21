<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Parámetros operativos y económicos editables por el administrador para KPI.
 *
 * @property int $id
 * @property string $clave
 * @property string $valor
 * @property string $unidad
 * @property bool $es_referencia
 * @property int|null $actualizado_por_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('parametros_operativos')]
#[Fillable([
    'clave',
    'valor',
    'unidad',
    'es_referencia',
    'actualizado_por_user_id',
])]
class ParametroOperativo extends Model
{
    public const PRECIO_COMBUSTIBLE_LITRO = 'precio_combustible_litro';

    public const HORAS_JORNADA = 'horas_jornada';

    public const UMBRAL_VISITA_UTIL_PCT = 'umbral_visita_util_pct';

    public const LIMITE_VELOCIDAD_KMH = 'limite_velocidad_kmh';

    public const RENDIMIENTO_REFERENCIA_KM_L = 'rendimiento_referencia_km_l';

    public const ARRIENDO_REFERENCIA_DIARIO = 'arriendo_referencia_diario';

    /**
     * Valores de referencia cuando la empresa aún no ha cargado sus datos reales.
     *
     * @var array<string, array{valor: float, unidad: string, etiqueta: string, descripcion: string, min: float, max: float}>
     */
    public const DEFINICIONES = [
        self::PRECIO_COMBUSTIBLE_LITRO => [
            'valor' => 1350.0,
            'unidad' => '$/L',
            'etiqueta' => 'Precio del combustible',
            'descripcion' => 'Costo por litro de diésel usado en los KPI de costos y conducción.',
            'min' => 100.0,
            'max' => 10000.0,
        ],
        self::HORAS_JORNADA => [
            'valor' => 8.0,
            'unidad' => 'h/día',
            'etiqueta' => 'Horas de jornada',
            'descripcion' => 'Duración de la jornada diaria por camión arrendado.',
            'min' => 1.0,
            'max' => 24.0,
        ],
        self::UMBRAL_VISITA_UTIL_PCT => [
            'valor' => 70.0,
            'unidad' => '%',
            'etiqueta' => 'Umbral de visita útil',
            'descripcion' => 'Porcentaje mínimo de llenado para considerar provechosa una visita.',
            'min' => 20.0,
            'max' => 95.0,
        ],
        self::LIMITE_VELOCIDAD_KMH => [
            'valor' => 50.0,
            'unidad' => 'km/h',
            'etiqueta' => 'Límite de velocidad urbano',
            'descripcion' => 'Umbral urbano en Chile sobre el cual se registran excesos de velocidad.',
            'min' => 20.0,
            'max' => 120.0,
        ],
        self::RENDIMIENTO_REFERENCIA_KM_L => [
            'valor' => 4.5,
            'unidad' => 'km/L',
            'etiqueta' => 'Rendimiento de referencia',
            'descripcion' => 'Respaldo usado si un camión aún no tiene su rendimiento propio cargado.',
            'min' => 1.0,
            'max' => 30.0,
        ],
        self::ARRIENDO_REFERENCIA_DIARIO => [
            'valor' => 95000.0,
            'unidad' => '$/día',
            'etiqueta' => 'Arriendo diario de referencia',
            'descripcion' => 'Respaldo usado si un camión aún no tiene costo de arriendo diario cargado.',
            'min' => 1000.0,
            'max' => 5000000.0,
        ],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'es_referencia' => 'boolean',
        ];
    }

    /**
     * Devuelve el mapa completo de parámetros combinando BD con valores de referencia.
     *
     * @return array<string, array{clave: string, etiqueta: string, descripcion: string, valor: float, unidad: string, es_referencia: bool, min: float, max: float}>
     */
    public static function catalogoResuelto(): array
    {
        $guardados = self::query()->get()->keyBy('clave');
        $resultado = [];

        foreach (self::DEFINICIONES as $clave => $def) {
            /** @var ParametroOperativo|null $fila */
            $fila = $guardados->get($clave);
            $resultado[$clave] = [
                'clave' => $clave,
                'etiqueta' => $def['etiqueta'],
                'descripcion' => $def['descripcion'],
                'valor' => $fila ? (float) $fila->valor : $def['valor'],
                'unidad' => $fila ? $fila->unidad : $def['unidad'],
                'es_referencia' => $fila ? (bool) $fila->es_referencia : true,
                'min' => $def['min'],
                'max' => $def['max'],
            ];
        }

        return $resultado;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por_user_id');
    }
}
