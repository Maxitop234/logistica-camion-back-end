<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Rechaza usuarios inactivos. En modo `permitir_chofer_inactivo` deja pasar
     * a choferes desactivados (para consultar estado, cerrar sesión o solicitar
     * reactivación). En rutas operativas bloquea al chofer inactivo con 403 sin
     * revocarle el token; para otros roles inactivos revoca sus tokens.
     */
    public function handle(Request $request, Closure $next, string $modo = 'strict'): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            if ($user->role === 'chofer' && $modo === 'permitir_chofer_inactivo') {
                return $next($request);
            }

            if ($user->role !== 'chofer') {
                $user->tokens()->delete();
            }

            return response()->json(['message' => 'La cuenta está desactivada.'], 403);
        }

        return $next($request);
    }
}
