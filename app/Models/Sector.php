<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\SectorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Sector de recolección de Somos Terranova: agrupa puntos de reciclaje que se
 * visitan en días fijos de la semana.
 *
 * @property int $id
 * @property string $nombre
 * @property array<int, int> $dias_recoleccion Días ISO (1 = lunes … 7 = domingo)
 * @property int $periodicidad_semanal
 * @property int|null $puntos_esperados
 * @property string|null $descripcion
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('sectores')]
#[Fillable([
    'nombre',
    'dias_recoleccion',
    'periodicidad_semanal',
    'puntos_esperados',
    'descripcion',
    'activo',
])]
class Sector extends Model
{
    /** @use HasFactory<SectorFactory> */
    use HasFactory;

    /**
     * Nombres de los días ISO (1 = lunes).
     *
     * @var array<int, string>
     */
    public const NOMBRES_DIAS = [
        1 => 'lunes',
        2 => 'martes',
        3 => 'miércoles',
        4 => 'jueves',
        5 => 'viernes',
        6 => 'sábado',
        7 => 'domingo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dias_recoleccion' => 'array',
            'periodicidad_semanal' => 'integer',
            'puntos_esperados' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Punto, $this>
     */
    public function puntos(): HasMany
    {
        return $this->hasMany(Punto::class);
    }

    /**
     * @param  Builder<Sector>  $query
     * @return Builder<Sector>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * Días de recolección normalizados: enteros 1–7, sin repetir y ordenados.
     *
     * @return array<int, int>
     */
    public function diasNormalizados(): array
    {
        return self::normalizarDias($this->dias_recoleccion ?? []);
    }

    /**
     * @param  array<int, mixed>  $dias
     * @return array<int, int>
     */
    public static function normalizarDias(array $dias): array
    {
        $enteros = array_filter(
            array_map(static fn (mixed $dia): int => (int) $dia, $dias),
            static fn (int $dia): bool => $dia >= 1 && $dia <= 7,
        );
        $unicos = array_values(array_unique($enteros));
        sort($unicos);

        return $unicos;
    }

    /**
     * @return array<int, string>
     */
    public function nombresDias(): array
    {
        return array_map(static fn (int $dia): string => self::NOMBRES_DIAS[$dia], $this->diasNormalizados());
    }

    public function esDiaDeRecoleccion(CarbonInterface $fecha): bool
    {
        return in_array($fecha->dayOfWeekIso, $this->diasNormalizados(), true);
    }

    /**
     * Primer día de recolección estrictamente posterior a la fecha local dada
     * (a medianoche, en la misma zona horaria). `null` si el sector no tiene días.
     */
    public function siguienteDiaDeRecoleccion(CarbonInterface $despuesDe): ?CarbonImmutable
    {
        if ($this->diasNormalizados() === []) {
            return null;
        }

        $dia = CarbonImmutable::instance($despuesDe)->startOfDay();
        for ($i = 1; $i <= 7; $i++) {
            $candidato = $dia->addDays($i);
            if ($this->esDiaDeRecoleccion($candidato)) {
                return $candidato;
            }
        }

        return null;
    }

    /**
     * Indica si la frecuencia declarada coincide con la cantidad de días.
     * Si no coincide, mandan los días (DATA_MODEL, BE-04).
     */
    public function diasCoincidenConPeriodicidad(): bool
    {
        return count($this->diasNormalizados()) === $this->periodicidad_semanal;
    }
}
