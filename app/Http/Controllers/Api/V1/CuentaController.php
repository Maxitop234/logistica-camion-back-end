<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Rules\ContrasenaSegura;
use App\Services\Cuentas\GestorDeCuentas;
use App\Services\Cuentas\VerificadorDeDosPasos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Seguridad de la cuenta propia: cambio de contraseña y verificación en dos pasos.
 */
class CuentaController extends Controller
{
    public function __construct(
        private readonly GestorDeCuentas $cuentas,
        private readonly VerificadorDeDosPasos $dosPasos,
    ) {}

    public function cambiarPassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->esCuentaDemoProtegida()) {
            return response()->json(['message' => GestorDeCuentas::MENSAJE_DEMO], 403);
        }

        $datos = $request->validate([
            'password_actual' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', new ContrasenaSegura],
        ]);

        if (! Hash::check($datos['password_actual'], $user->password)) {
            return response()->json([
                'message' => 'La contraseña actual no es correcta.',
                'errors' => ['password_actual' => ['La contraseña actual no es correcta.']],
            ], 422);
        }

        if (Hash::check($datos['password'], $user->password)) {
            return response()->json([
                'message' => 'La contraseña nueva debe ser distinta de la actual.',
                'errors' => ['password' => ['La contraseña nueva debe ser distinta de la actual.']],
            ], 422);
        }

        $this->cuentas->fijarPassword($user, $datos['password'], false, $user->currentAccessToken()->getKey());
        AuditLog::registrar('password_cambiada', $user, $request, ['origen' => 'propia']);

        return response()->json(['message' => 'Contraseña actualizada. Se cerraron tus otras sesiones.', 'user' => new UserResource($user->refresh())]);
    }

    public function estadoDosPasos(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'activo' => $user->hasEnabledTwoFactorAuthentication(),
            'disponible' => ! $user->esCuentaDemoProtegida(),
        ]);
    }

    /**
     * Genera el secreto y el QR; el 2FA no queda activo hasta confirmar un código.
     */
    public function iniciarDosPasos(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->esCuentaDemoProtegida()) {
            return response()->json(['message' => GestorDeCuentas::MENSAJE_DEMO], 403);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return response()->json(['message' => 'La verificación en dos pasos ya está activa.'], 409);
        }

        $secreto = $this->dosPasos->iniciar($user);

        return response()->json([
            'secreto' => $secreto,
            'url_otpauth' => $user->twoFactorQrCodeUrl(),
            'qr_svg' => $user->twoFactorQrCodeSvg(),
        ]);
    }

    public function confirmarDosPasos(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->esCuentaDemoProtegida()) {
            return response()->json(['message' => GestorDeCuentas::MENSAJE_DEMO], 403);
        }

        $datos = $request->validate(['codigo' => ['required', 'string', 'max:12']]);

        if ($user->hasEnabledTwoFactorAuthentication() || $user->two_factor_secret === null) {
            return response()->json(['message' => 'No hay una activación pendiente. Empieza de nuevo.'], 409);
        }

        if (! $this->dosPasos->codigoValido($user, $datos['codigo'])) {
            return response()->json([
                'message' => 'El código no es correcto.',
                'errors' => ['codigo' => ['El código no es correcto.']],
            ], 422);
        }

        $codigos = $this->dosPasos->confirmar($user);
        AuditLog::registrar('dos_pasos_activado', $user, $request);

        return response()->json(['activo' => true, 'codigos_recuperacion' => $codigos]);
    }

    public function desactivarDosPasos(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->esCuentaDemoProtegida()) {
            return response()->json(['message' => GestorDeCuentas::MENSAJE_DEMO], 403);
        }

        $datos = $request->validate(['password_actual' => ['required', 'string']]);

        if (! Hash::check($datos['password_actual'], $user->password)) {
            return response()->json([
                'message' => 'La contraseña actual no es correcta.',
                'errors' => ['password_actual' => ['La contraseña actual no es correcta.']],
            ], 422);
        }

        $this->dosPasos->desactivar($user);
        AuditLog::registrar('dos_pasos_desactivado', $user, $request, ['origen' => 'propia']);

        return response()->json(['activo' => false]);
    }
}
