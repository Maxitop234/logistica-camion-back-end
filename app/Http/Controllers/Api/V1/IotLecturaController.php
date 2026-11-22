<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LecturaPuntoResource;
use App\Http\Resources\PuntoResource;
use App\Models\Dispositivo;
use App\Services\Iot\IotException;
use App\Services\Iot\RecepcionDeLecturas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IotLecturaController extends Controller
{
    /**
     * Ingesta de lecturas del sensor (ESP32 + ultrasónico). Se autentica con el
     * token propio del dispositivo en `X-IoT-Token` (o `Authorization: Bearer`),
     * sin cuentas humanas (ADR-0012). Un token por punto anterior sigue valiendo.
     */
    public function store(Request $request, RecepcionDeLecturas $recepcion): JsonResponse
    {
        $token = $request->header('X-IoT-Token') ?: $request->bearerToken();

        try {
            $resultado = $recepcion->recibir(is_string($token) ? $token : null, $request->all());
        } catch (IotException $e) {
            return response()->json(['message' => $e->getMessage()], $e->estado);
        }

        $dispositivo = $resultado['dispositivo'];

        return response()->json([
            'data' => $resultado['punto'] !== null ? (new PuntoResource($resultado['punto']))->resolve() : null,
            'lectura' => $resultado['lectura'] !== null ? (new LecturaPuntoResource($resultado['lectura']))->resolve() : null,
            'valida' => $resultado['valida'],
            'motivo_invalido' => $resultado['motivo_invalido'],
            'duplicada' => $resultado['duplicada'],
            'configuracion' => $dispositivo instanceof Dispositivo ? [
                'intervalo_lectura_seg' => $dispositivo->intervalo_lectura_seg,
            ] : null,
            'hora_servidor' => now()->utc()->toIso8601String(),
        ], $resultado['duplicada'] ? 200 : 201);
    }
}
