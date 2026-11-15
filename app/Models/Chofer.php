<?php

namespace App\Models;

use Database\Factories\ChoferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $nombre_cifrado
 * @property string $rut_hash
 * @property string $licencia_clase
 * @property string|null $telefono_cifrado
 * @property bool $disponible
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('choferes')]
#[Fillable(['user_id', 'nombre_cifrado', 'rut_hash', 'licencia_clase', 'telefono_cifrado', 'disponible'])]
class Chofer extends Model
{
    /** @use HasFactory<ChoferFactory> */
    use HasFactory;

    /**
     * Normaliza un RUT chileno: sin puntos, guion ni espacios y con K en mayúscula.
     */
    public static function normalizarRut(string $rut): string
    {
        return strtoupper(preg_replace('/[^0-9kK]/', '', $rut));
    }

    /**
     * Calcula el hash HMAC-SHA256 del RUT normalizado, usado para búsquedas y unicidad.
     */
    public static function hashRut(string $rut): string
    {
        return hash_hmac('sha256', self::normalizarRut($rut), config('app.key'));
    }

    /**
     * Valida el formato y el dígito verificador (módulo 11) de un RUT chileno.
     */
    public static function rutEsValido(string $rut): bool
    {
        if (! preg_match('/^\d{1,2}(\.?\d{3}){2}-?[\dkK]$/', trim($rut))) {
            return false;
        }

        $normalizado = self::normalizarRut($rut);
        $cuerpo = substr($normalizado, 0, -1);
        $dv = substr($normalizado, -1);

        $suma = 0;
        $multiplicador = 2;
        foreach (array_reverse(str_split($cuerpo)) as $digito) {
            $suma += (int) $digito * $multiplicador;
            $multiplicador = $multiplicador === 7 ? 2 : $multiplicador + 1;
        }

        $esperado = match (11 - ($suma % 11)) {
            11 => '0',
            10 => 'K',
            default => (string) (11 - ($suma % 11)),
        };

        return $dv === $esperado;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nombre_cifrado' => 'encrypted',
            'telefono_cifrado' => 'encrypted',
            'disponible' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<Camion, $this>
     */
    public function camionActual(): HasOne
    {
        return $this->hasOne(Camion::class, 'chofer_actual_id');
    }
}
