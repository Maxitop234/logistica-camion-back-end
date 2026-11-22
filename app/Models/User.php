<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\RestablecerPasswordAdministrador;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string $role
 * @property bool $is_active
 * @property bool $debe_cambiar_password
 * @property bool $cuenta_protegida
 * @property Carbon|null $password_cambiada_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'debe_cambiar_password', 'cuenta_protegida', 'password_cambiada_en'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
            'debe_cambiar_password' => 'boolean',
            'cuenta_protegida' => 'boolean',
            'password_cambiada_en' => 'datetime',
        ];
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new RestablecerPasswordAdministrador($token));
    }

    /**
     * En la demostración las cuentas sembradas no pueden cambiar su contraseña
     * ni activar la verificación en dos pasos.
     */
    public function esCuentaDemoProtegida(): bool
    {
        return (bool) config('app.demo_mode') && $this->cuenta_protegida;
    }

    /**
     * @return HasOne<Chofer, $this>
     */
    public function chofer(): HasOne
    {
        return $this->hasOne(Chofer::class);
    }

    /**
     * @return HasOne<Administrador, $this>
     */
    public function administrador(): HasOne
    {
        return $this->hasOne(Administrador::class);
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
