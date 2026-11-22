<?php

namespace App\Services\Cuentas;

use App\Models\User;
use Illuminate\Support\Collection;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;

/**
 * TOTP estándar (RFC 6238) con las piezas de Fortify: secreto cifrado en
 * `users`, verificación con ventana y rechazo de códigos ya usados.
 */
class VerificadorDeDosPasos
{
    public const CODIGOS_DE_RECUPERACION = 8;

    public function __construct(private readonly TwoFactorAuthenticationProvider $totp) {}

    /**
     * Genera y guarda un secreto nuevo sin confirmar (2FA aún inactivo).
     */
    public function iniciar(User $user): string
    {
        $secreto = $this->totp->generateSecretKey();
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secreto),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $secreto;
    }

    public function secreto(User $user): ?string
    {
        return $user->two_factor_secret
            ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret)
            : null;
    }

    public function codigoValido(User $user, string $codigo): bool
    {
        $secreto = $this->secreto($user);
        $codigo = preg_replace('/\s+/', '', $codigo) ?? '';

        return $secreto !== null && preg_match('/^\d{6}$/', $codigo) === 1 && $this->totp->verify($secreto, $codigo);
    }

    /**
     * Confirma el primer código y devuelve los códigos de recuperación (única vez).
     *
     * @return array<int, string>
     */
    public function confirmar(User $user): array
    {
        $codigos = Collection::times(self::CODIGOS_DE_RECUPERACION, fn () => RecoveryCode::generate())->all();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($codigos)),
        ])->save();

        return $codigos;
    }

    public function desactivar(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * Usa un código de recuperación: es de un solo uso, así que se elimina.
     */
    public function consumirCodigoRecuperacion(User $user, string $codigo): bool
    {
        if (! $user->two_factor_recovery_codes) {
            return false;
        }

        $codigo = trim($codigo);
        $codigos = collect($user->recoveryCodes());
        $coincide = $codigos->first(fn (string $guardado) => hash_equals($guardado, $codigo));

        if ($coincide === null) {
            return false;
        }

        $user->forceFill([
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
                json_encode($codigos->reject(fn (string $c) => $c === $coincide)->values()->all())
            ),
        ])->save();

        return true;
    }
}
