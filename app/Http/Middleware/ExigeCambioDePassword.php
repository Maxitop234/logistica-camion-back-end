<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si la cuenta está marcada «debe cambiar contraseña», bloquea con 403 y el
 * código `cambio_password_requerido` todo salvo /auth/me, /auth/logout y el
 * propio cambio de contraseña (que no pasan por este middleware).
 */
class ExigeCambioDePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->debe_cambiar_password) {
            return response()->json([
                'message' => 'Debes cambiar tu contraseña antes de continuar.',
                'code' => 'cambio_password_requerido',
            ], 403);
        }

        return $next($request);
    }
}
