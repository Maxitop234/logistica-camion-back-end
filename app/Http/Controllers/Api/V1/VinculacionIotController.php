<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dispositivo\AnunciarDispositivoRequest;
use App\Http\Requests\Dispositivo\EstadoDispositivoRequest;
use App\Services\Iot\IotException;
use App\Services\Iot\VinculacionDeDispositivos;
use Illuminate\Http\JsonResponse;

/**
 * Endpoints públicos (con límite de intentos) del sensor para vincularse sin
 * que nadie maneje tokens: se anuncia con el código de su etiqueta y retira su
 * token una sola vez cuando el administrador lo aprueba.
 */
class VinculacionIotController extends Controller
{
    public function anunciar(AnunciarDispositivoRequest $request, VinculacionDeDispositivos $vinculacion): JsonResponse
    {
        try {
            $resultado = $vinculacion->anunciar($request->validated());
        } catch (IotException $e) {
            return response()->json(['message' => $e->getMessage()], $e->estado);
        }

        return response()->json([
            'estado' => $resultado['dispositivo']->estado,
            'mensaje' => 'Sensor anunciado. Espera la aprobación del administrador.',
            'hora_servidor' => now()->utc()->toIso8601String(),
        ], $resultado['creado'] ? 201 : 200);
    }

    public function estado(EstadoDispositivoRequest $request, VinculacionDeDispositivos $vinculacion): JsonResponse
    {
        try {
            $resultado = $vinculacion->consultarEstado($request->validated('codigo_hardware'), $request->validated('codigo_vinculacion'));
        } catch (IotException $e) {
            return response()->json(['message' => $e->getMessage()], $e->estado);
        }

        return response()->json([
            'estado' => $resultado['estado'],
            // Solo viene en la primera consulta posterior a la aprobación (o a una rotación).
            'token' => $resultado['token'],
            'intervalo_lectura_seg' => $resultado['intervalo_lectura_seg'],
            'endpoint_lecturas' => '/api/v1/iot/lecturas',
            'hora_servidor' => now()->utc()->toIso8601String(),
        ]);
    }
}
