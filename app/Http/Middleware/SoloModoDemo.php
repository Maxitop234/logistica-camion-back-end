<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea con 403 las rutas que solo existen en el ambiente de demostración
 * (`APP_DEMO_MODE=true`), como el simulador de sensores.
 */
class SoloModoDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.demo_mode')) {
            return response()->json(['message' => 'Esta función solo existe en el modo demostración.'], 403);
        }

        return $next($request);
    }
}
