<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $punto_id
 * @property string|null $lectura_uuid
 * @property string $origen
 * @property string|null $distancia_cm
 * @property int $porcentaje_llenado
 * @property string $estado_llenado
 * @property string|null $bateria_voltaje
 * @property int|null $registrada_por_user_id
 * @property Carbon|null $medido_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('lecturas_puntos')]
#[Fillable([
    'punto_id',
    'lectura_uuid',
    'origen',
    'distancia_cm',
    'porcentaje_llenado',
    'estado_llenado',
    'bateria_voltaje',
    'registrada_por_user_id',
    'medido_en',
])]
class LecturaPunto extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'distancia_cm' => 'decimal:2',
            'porcentaje_llenado' => 'integer',
            'bateria_voltaje' => 'decimal:2',
            'medido_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Punto, $this>
     */
    public function punto(): BelongsTo
    {
        return $this->belongsTo(Punto::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrada_por_user_id');
    }
}
