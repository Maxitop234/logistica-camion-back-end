<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Rules\ContrasenaSegura;
use App\Services\Cuentas\GestorDeCuentas;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Recuperación por correo, opcional y solo para administradores. La respuesta
 * de la solicitud es siempre la misma, exista o no la cuenta.
 */
class RecuperacionPasswordController extends Controller
{
    public function __construct(private readonly GestorDeCuentas $cuentas) {}

    public function solicitar(Request $request): JsonResponse
    {
        if (! $this->cuentas->recuperacionPorCorreoDisponible()) {
            return response()->json(['message' => 'No disponible.'], 404);
        }

        $datos = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $user = User::where('email', Str::lower($datos['email']))->first();

        if ($user && $user->role === 'administrador' && $user->is_active && ! $user->esCuentaDemoProtegida()) {
            Password::sendResetLink(['email' => $user->email]);
            AuditLog::registrar('password_recuperacion_solicitada', $user, $request);
        }

        return response()->json([
            'message' => 'Si el correo corresponde a un administrador, enviamos un enlace para restablecer la contraseña. Vence en 60 minutos.',
        ]);
    }

    public function restablecer(Request $request): JsonResponse
    {
        if (! $this->cuentas->recuperacionPorCorreoDisponible()) {
            return response()->json(['message' => 'No disponible.'], 404);
        }

        $datos = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'confirmed', new ContrasenaSegura],
        ]);

        $user = User::where('email', Str::lower($datos['email']))->first();
        if (! $user || $user->role !== 'administrador' || ! $user->is_active || $user->esCuentaDemoProtegida()) {
            return response()->json(['message' => 'El enlace no es válido o venció.'], 422);
        }

        $estado = Password::reset(
            ['email' => $user->email, 'token' => $datos['token'], 'password' => $datos['password']],
            function (User $user, string $password) use ($request): void {
                $this->cuentas->fijarPassword($user, $password, false);
                AuditLog::registrar('password_restablecida_por_correo', $user, $request);
                event(new PasswordReset($user));
            },
        );

        if ($estado !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'El enlace no es válido o venció.'], 422);
        }

        return response()->json(['message' => 'Contraseña restablecida. Ya puedes iniciar sesión.']);
    }
}
