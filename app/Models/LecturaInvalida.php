<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lectura que el servidor descartó por imposible (sin eco, zona ciega o fuera
 * de rango). No entra a los KPI ni cambia el estado del punto.
 *
 * @property int $id
 * @property int $dispositivo_id
 * @property int|null $punto_id
 * @property string|null $lectura_uuid
 * @property string|null $distancia_cm
 * @property string $motivo
 * @property string|null $bateria_voltaje
 * @property int|null $rssi_dbm
 * @property Carbon $medido_en
 */
#[Table('lecturas_invalidas')]
#[Fillable(['dispositivo_id', 'punto_id', 'lectura_uuid', 'distancia_cm', 'motivo', 'bateria_voltaje', 'rssi_dbm', 'medido_en'])]
class LecturaInvalida extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'distancia_cm' => 'decimal:2',
            'bateria_voltaje' => 'decimal:2',
            'rssi_dbm' => 'integer',
            'medido_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Dispositivo, $this>
     */
    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }
}
