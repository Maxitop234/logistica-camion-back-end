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
 * @property string $motivo
 * @property string $estado
 * @property int|null $resuelta_por_user_id
 * @property Carbon|null $resuelta_en
 * @property string|null $respuesta_admin
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('solicitudes_borrado_licencia')]
#[Fillable([
    'chofer_id',
    'motivo',
    'estado',
    'resuelta_por_user_id',
    'resuelta_en',
    'respuesta_admin',
])]
class SolicitudBorradoLicencia extends Model
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
