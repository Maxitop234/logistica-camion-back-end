<?php

namespace App\Models;

use Database\Factories\ChoferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string $licencia_estado
 * @property string|null $licencia_archivo_path
 * @property string|null $licencia_archivo_nombre
 * @property string|null $licencia_archivo_sha256
 * @property Carbon|null $licencia_subida_en
 * @property int|null $licencia_verificada_por_user_id
 * @property Carbon|null $licencia_verificada_en
 * @property bool $licencia_borrado_habilitado
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('choferes')]
#[Fillable([
    'user_id',
    'nombre_cifrado',
    'rut_hash',
    'licencia_clase',
    'telefono_cifrado',
    'disponible',
    'licencia_estado',
    'licencia_archivo_path',
    'licencia_archivo_nombre',
    'licencia_archivo_sha256',
    'licencia_subida_en',
    'licencia_verificada_por_user_id',
    'licencia_verificada_en',
    'licencia_borrado_habilitado',
])]
class Chofer extends Model
{
    /** @use HasFactory<ChoferFactory> */
    use HasFactory;

    public const LICENCIA_PENDIENTE = 'pendiente';

    public const LICENCIA_VERIFICADA = 'verificada';

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

    public function tieneArchivoLicencia(): bool
    {
        return is_string($this->licencia_archivo_path) && $this->licencia_archivo_path !== '';
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
            'licencia_subida_en' => 'datetime',
            'licencia_verificada_en' => 'datetime',
            'licencia_borrado_habilitado' => 'boolean',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function licenciaVerificadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'licencia_verificada_por_user_id');
    }

    /**
     * @return HasMany<SolicitudBorradoLicencia, $this>
     */
    public function solicitudesBorradoLicencia(): HasMany
    {
        return $this->hasMany(SolicitudBorradoLicencia::class);
    }

    /**
     * @return HasOne<SolicitudBorradoLicencia, $this>
     */
    public function ultimaSolicitudBorrado(): HasOne
    {
        return $this->hasOne(SolicitudBorradoLicencia::class)->latestOfMany();
    }
}
