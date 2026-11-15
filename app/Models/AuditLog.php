<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $accion
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $detalles
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'accion', 'ip_address', 'user_agent', 'detalles'])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    /**
     * Registra un evento con la IP y el user agent de la petición.
     *
     * @param  array<string, mixed>  $detalles
     */
    public static function registrar(string $accion, ?User $user, Request $request, array $detalles = []): self
    {
        return self::create([
            'user_id' => $user?->id,
            'accion' => $accion,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'detalles' => $detalles ?: null,
        ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'detalles' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
