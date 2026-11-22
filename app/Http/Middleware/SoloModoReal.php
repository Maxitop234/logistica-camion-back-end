<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea con 403 las rutas que solo existen en el ambiente real
 * (`APP_DEMO_MODE=false`), como la gestión de administradores.
 */
class SoloModoReal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.demo_mode')) {
            return response()->json(['message' => 'No disponible en la demostración.'], 403);
        }

        return $next($request);
    }
}
