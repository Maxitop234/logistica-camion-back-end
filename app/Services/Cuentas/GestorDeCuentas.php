<?php

namespace App\Services\Cuentas;

use App\Models\User;

/**
 * Operaciones de seguridad sobre cuentas: contraseñas, tokens con vencimiento
 * por rol y reglas comunes (cuentas demo protegidas, último administrador).
 */
class GestorDeCuentas
{
    private const ALFABETO = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const MENSAJE_DEMO = 'No disponible en la demostración.';

    /**
     * Contraseña aleatoria de 16 caracteres sin símbolos ambiguos.
     */
    public function generarPassword(): string
    {
        $clave = '';
        $max = strlen(self::ALFABETO) - 1;
        for ($i = 0; $i < 16; $i++) {
            $clave .= self::ALFABETO[random_int(0, $max)];
        }

        return $clave;
    }

    /**
     * Fija una contraseña nueva y revoca los tokens (todos, o todos menos uno).
     */
    public function fijarPassword(User $user, string $nueva, bool $exigirCambio, ?int $conservarTokenId = null): void
    {
        $user->forceFill([
            'password' => $nueva,
            'debe_cambiar_password' => $exigirCambio,
            'password_cambiada_en' => now(),
        ])->save();

        $this->revocarTokens($user, $conservarTokenId);
    }

    public function revocarTokens(User $user, ?int $conservarTokenId = null): void
    {
        $tokens = $user->tokens();
        if ($conservarTokenId !== null) {
            $tokens->where('id', '!=', $conservarTokenId);
        }
        $tokens->delete();
    }

    public function crearToken(User $user, string $dispositivo): string
    {
        $vence = $user->role === 'administrador'
            ? now()->addHours(max(1, (int) config('ecoruta.sesion.administrador_horas')))
            : now()->addDays(max(1, (int) config('ecoruta.sesion.chofer_dias')));

        return $user->createToken($dispositivo, ['*'], $vence)->plainTextToken;
    }

    public function esUltimoAdministradorActivo(User $user): bool
    {
        if ($user->role !== 'administrador' || ! $user->is_active) {
            return false;
        }

        return ! User::where('role', 'administrador')->where('is_active', true)->where('id', '!=', $user->id)->exists();
    }

    /**
     * Hay un correo real configurado: ni `log` ni `array` (que no envían nada).
     */
    public function correoConfigurado(): bool
    {
        $mailer = (string) config('mail.default');

        return $mailer !== '' && ! in_array($mailer, ['log', 'array'], true);
    }

    public function recuperacionPorCorreoDisponible(): bool
    {
        return (bool) config('ecoruta.recuperacion_por_correo.habilitada') && $this->correoConfigurado();
    }
}
