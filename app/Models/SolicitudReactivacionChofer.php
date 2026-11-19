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
 * @property int $ciclo_desactivacion
 * @property string|null $motivo_desactivacion
 * @property string $motivo
 * @property string $estado
 * @property int|null $resuelta_por_user_id
 * @property Carbon|null $resuelta_en
 * @property string|null $motivo_rechazo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('solicitudes_reactivacion_chofer')]
#[Fillable([
    'chofer_id',
    'ciclo_desactivacion',
    'motivo_desactivacion',
    'motivo',
    'estado',
    'resuelta_por_user_id',
    'resuelta_en',
    'motivo_rechazo',
])]
class SolicitudReactivacionChofer extends Model
{
    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_APROBADA = 'aprobada';

    public const ESTADO_RECHAZADA = 'rechazada';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ciclo_desactivacion' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function resueltaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelta_por_user_id');
    }
}
