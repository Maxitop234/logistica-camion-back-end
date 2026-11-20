<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Punto\StoreIotLecturaRequest;
use App\Http\Resources\LecturaPuntoResource;
use App\Http\Resources\PuntoResource;
use App\Models\Punto;
use App\Services\PuntoTelemetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class IotLecturaController extends Controller
{
    /**
     * Endpoint de ingesta para sensores físicos ESP32 (JSN-SR04T / HC-SR04).
     * Se autentica mediante `Authorization: Bearer <sensor_token>` contra
     * `puntos.sensor_token_hash`, sin usar cuentas humanas.
     */
    public function store(StoreIotLecturaRequest $request, PuntoTelemetryService $telemetry): JsonResponse
    {
        $token = $request->bearerToken();
        if (! is_string($token) || trim($token) === '') {
            return response()->json(['message' => 'Token de sensor requerido.'], 401);
        }

        $punto = Punto::where('sensor_token_hash', Punto::hashSensorToken($token))->first();
        if (! $punto instanceof Punto) {
            return response()->json(['message' => 'Token de sensor inválido.'], 401);
        }

        if (! $punto->activo) {
            return response()->json(['message' => 'El punto asociado a este sensor está inactivo.'], 403);
        }

        $datos = $request->validated();
        $medidoEn = isset($datos['medido_en']) ? Carbon::parse((string) $datos['medido_en']) : null;

        $resultado = $telemetry->registrarLectura(
            punto: $punto,
            origen: Punto::ORIGEN_SENSOR_IOT,
            distanciaCm: isset($datos['distancia_cm']) ? (float) $datos['distancia_cm'] : null,
            porcentajeDirecto: isset($datos['porcentaje_llenado']) ? (int) $datos['porcentaje_llenado'] : null,
            bateriaVoltaje: isset($datos['bateria_voltaje']) ? (float) $datos['bateria_voltaje'] : null,
            medidoEn: $medidoEn,
            lecturaUuid: $datos['lectura_uuid'] ?? null,
        );

        return response()->json([
            'data' => (new PuntoResource($resultado['punto']))->resolve(),
            'lectura' => (new LecturaPuntoResource($resultado['lectura']))->resolve(),
            'duplicada' => $resultado['duplicada'],
        ], $resultado['duplicada'] ? 200 : 201);
    }
}
