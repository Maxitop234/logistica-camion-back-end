<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DesafioDosPasosRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Cuentas\GestorDeCuentas;
use App\Services\Cuentas\VerificadorDeDosPasos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const MAX_INTENTOS = 5;

    private const VENTANA_SEGUNDOS = 60;

    private const DESAFIO_MINUTOS = 5;

    public function __construct(
        private readonly GestorDeCuentas $cuentas,
        private readonly VerificadorDeDosPasos $dosPasos,
    ) {}

    /**
     * Qué ofrece este servidor antes de iniciar sesión (sin datos de cuentas).
     */
    public function opciones(): JsonResponse
    {
        return response()->json([
            'modo_demo' => (bool) config('app.demo_mode'),
            'recuperacion_por_correo' => $this->cuentas->recuperacionPorCorreoDisponible(),
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $email = Str::lower($request->validated('email'));
        $deviceName = $request->validated('device_name');
        $clave = $email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($clave, self::MAX_INTENTOS)) {
            return response()->json([
                'message' => 'Demasiados intentos. Intenta nuevamente en '.RateLimiter::availableIn($clave).' segundos.',
            ], 429);
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            RateLimiter::hit($clave, self::VENTANA_SEGUNDOS);
            AuditLog::registrar('failed_login', $user, $request, [
                'email' => $email,
                'motivo' => 'credenciales_invalidas',
                'device_name' => $deviceName,
            ]);

            return response()->json(['message' => 'Credenciales inválidas.'], 401);
        }

        if (! $user->is_active && $user->role !== 'chofer') {
            RateLimiter::hit($clave, self::VENTANA_SEGUNDOS);
            AuditLog::registrar('failed_login', $user, $request, [
                'email' => $email,
                'motivo' => 'usuario_inactivo',
                'device_name' => $deviceName,
            ]);

            return response()->json(['message' => 'La cuenta está desactivada.'], 403);
        }

        RateLimiter::clear($clave);

        if ($user->role === 'administrador' && $user->hasEnabledTwoFactorAuthentication()) {
            $desafio = Str::random(48);
            Cache::put($this->claveDesafio($desafio), [
                'user_id' => $user->id,
                'device_name' => $deviceName,
                'intentos' => 0,
            ], now()->addMinutes(self::DESAFIO_MINUTOS));

            return response()->json([
                'requiere_dos_pasos' => true,
                'desafio' => $desafio,
                'vence_en_segundos' => self::DESAFIO_MINUTOS * 60,
            ]);
        }

        return $this->emitirSesion($user, $deviceName, $request);
    }

    /**
     * Segundo paso del login: código de la app autenticadora o de recuperación.
     * Comparte el límite de intentos del login (5 por minuto por cuenta e IP).
     */
    public function desafioDosPasos(DesafioDosPasosRequest $request): JsonResponse
    {
        $clave = $this->claveDesafio($request->validated('desafio'));
        $desafio = Cache::get($clave);

        if (! is_array($desafio)) {
            return response()->json(['message' => 'La verificación expiró. Inicia sesión de nuevo.'], 401);
        }

        $user = User::query()->find((int) $desafio['user_id']);
        $limite = '2fa|'.$desafio['user_id'].'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($limite, self::MAX_INTENTOS)) {
            return response()->json([
                'message' => 'Demasiados intentos. Intenta nuevamente en '.RateLimiter::availableIn($limite).' segundos.',
            ], 429);
        }

        $valido = $user !== null
            && $user->is_active
            && $user->hasEnabledTwoFactorAuthentication()
            && (
                ($request->filled('codigo') && $this->dosPasos->codigoValido($user, (string) $request->validated('codigo')))
                || ($request->filled('codigo_recuperacion') && $this->dosPasos->consumirCodigoRecuperacion($user, (string) $request->validated('codigo_recuperacion')))
            );

        if (! $valido) {
            RateLimiter::hit($limite, self::VENTANA_SEGUNDOS);
            $desafio['intentos']++;
            if ($desafio['intentos'] >= self::MAX_INTENTOS) {
                Cache::forget($clave);
            } else {
                Cache::put($clave, $desafio, now()->addMinutes(self::DESAFIO_MINUTOS));
            }
            AuditLog::registrar('failed_login', $user, $request, [
                'motivo' => 'codigo_dos_pasos_invalido',
                'device_name' => $desafio['device_name'],
            ]);

            return response()->json(['message' => 'Código inválido.'], 401);
        }

        Cache::forget($clave);
        RateLimiter::clear($limite);

        return $this->emitirSesion($user, $desafio['device_name'], $request, ['dos_pasos' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->currentAccessToken()->delete();
        AuditLog::registrar('logout', $user, $request);

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function emitirSesion(User $user, string $deviceName, Request $request, array $extra = []): JsonResponse
    {
        $token = $this->cuentas->crearToken($user, $deviceName);
        AuditLog::registrar('login', $user, $request, [
            'device_name' => $deviceName,
            ...$extra,
            ...(! $user->is_active ? ['cuenta_inactiva' => true] : []),
        ]);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'role' => $user->role,
            'user' => new UserResource($user),
        ]);
    }

    private function claveDesafio(string $desafio): string
    {
        return 'login-dos-pasos:'.hash('sha256', $desafio);
    }
}
