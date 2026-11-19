<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const MAX_INTENTOS = 5;

    private const VENTANA_SEGUNDOS = 60;

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

        $token = $user->createToken($deviceName)->plainTextToken;
        AuditLog::registrar('login', $user, $request, [
            'device_name' => $deviceName,
            ...(! $user->is_active ? ['cuenta_inactiva' => true] : []),
        ]);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'role' => $user->role,
            'user' => new UserResource($user),
        ]);
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
}
