<?php

namespace App\Models;

use Database\Factories\PuntoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $nombre
 * @property string $direccion
 * @property string $tipo_punto
 * @property string $latitud
 * @property string $longitud
 * @property string $estado_llenado
 * @property int $porcentaje_llenado
 * @property string|null $peso_actual_kg
 * @property Carbon|null $ultima_actualizacion_estado
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('puntos')]
#[Fillable([
    'nombre',
    'direccion',
    'tipo_punto',
    'latitud',
    'longitud',
    'estado_llenado',
    'porcentaje_llenado',
    'peso_actual_kg',
    'ultima_actualizacion_estado',
    'activo',
])]
class Punto extends Model
{
    /** @use HasFactory<PuntoFactory> */
    use HasFactory;

    public const ESTADO_VACIO = 'vacio';

    public const ESTADO_BAJO = 'bajo';

    public const ESTADO_MEDIO = 'medio';

    public const ESTADO_ALTO = 'alto';

    public const ESTADO_LLENO = 'lleno';

    /**
     * @var array<int, string>
     */
    public const ESTADOS_LISTOS = [self::ESTADO_ALTO, self::ESTADO_LLENO];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'porcentaje_llenado' => 'integer',
            'ultima_actualizacion_estado' => 'datetime',
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
}
