<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tramo en que el camión se alejó de la ruta planificada.
 *
 * @property int $id
 * @property int $ruta_id
 * @property int $chofer_id
 * @property int $camion_id
 * @property Carbon $inicio_en
 * @property Carbon $fin_en
 * @property int $distancia_m
 * @property int $max_separacion_m
 * @property string $geometria_real
 * @property string|null $geometria_planificada
 * @property string $clave_tramo
 * @property float $centro_latitud
 * @property float $centro_longitud
 * @property string|null $motivo_codigo
 * @property string|null $motivo_texto
 * @property Carbon|null $revisado_en
 */
#[Table('desvios_ruta')]
#[Fillable([
    'ruta_id', 'chofer_id', 'camion_id', 'inicio_en', 'fin_en', 'distancia_m', 'max_separacion_m',
    'geometria_real', 'geometria_planificada', 'clave_tramo', 'centro_latitud', 'centro_longitud',
    'motivo_codigo', 'motivo_texto', 'revisado_en',
])]
class DesvioRuta extends Model
{
    public const MOTIVO_CALLE_SENTIDO = 'calle_otro_sentido';

    public const MOTIVO_CALLE_CERRADA = 'calle_cerrada_obras';

    public const MOTIVO_NO_PASA_CAMION = 'no_pasa_camion';

    public const MOTIVO_ATAJO = 'atajo_mas_rapido';

    public const MOTIVO_OTRO = 'otro';

    /**
     * @return list<string>
     */
    public static function motivosValidos(): array
    {
        return [
            self::MOTIVO_CALLE_SENTIDO,
            self::MOTIVO_CALLE_CERRADA,
            self::MOTIVO_NO_PASA_CAMION,
            self::MOTIVO_ATAJO,
            self::MOTIVO_OTRO,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inicio_en' => 'datetime',
            'fin_en' => 'datetime',
            'distancia_m' => 'integer',
            'max_separacion_m' => 'integer',
            'centro_latitud' => 'float',
            'centro_longitud' => 'float',
            'revisado_en' => 'datetime',
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
     * @return BelongsTo<Chofer, $this>
     */
    public function chofer(): BelongsTo
    {
        return $this->belongsTo(Chofer::class);
    }
}
