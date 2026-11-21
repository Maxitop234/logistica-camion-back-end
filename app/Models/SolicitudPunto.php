<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $chofer_id
 * @property string $nombre
 * @property bool $nombre_automatico
 * @property string $direccion
 * @property string $tipo_punto
 * @property string $latitud
 * @property string $longitud
 * @property string|null $observacion
 * @property string $estado
 * @property int|null $punto_creado_id
 * @property int|null $resuelta_por_user_id
 * @property Carbon|null $resuelta_en
 * @property string|null $motivo_rechazo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('solicitudes_punto')]
#[Fillable([
    'chofer_id',
    'nombre',
    'nombre_automatico',
    'direccion',
    'tipo_punto',
    'latitud',
    'longitud',
    'observacion',
    'estado',
    'punto_creado_id',
    'resuelta_por_user_id',
    'resuelta_en',
    'motivo_rechazo',
])]
class SolicitudPunto extends Model
{
    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_APROBADA = 'aprobada';

    public const ESTADO_RECHAZADA = 'rechazada';

    /** @var array<string, mixed> */
    protected $attributes = [
        'nombre_automatico' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nombre_automatico' => 'boolean',
            'resuelta_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Chofer, $this>
     */
    public function chofer(): BelongsTo
    {
        return $this->belongsTo(Chofer::class);
    }

    /**
     * @return BelongsTo<Punto, $this>
     */
    public function puntoCreado(): BelongsTo
    {
        return $this->belongsTo(Punto::class, 'punto_creado_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resueltaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelta_por_user_id');
    }
}
