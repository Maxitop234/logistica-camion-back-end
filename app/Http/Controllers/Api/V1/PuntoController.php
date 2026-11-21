<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Punto\StoreIotLecturaRequest;
use App\Http\Requests\Punto\StorePuntoRequest;
use App\Http\Requests\Punto\UpdatePuntoRequest;
use App\Http\Resources\LecturaPuntoResource;
use App\Http\Resources\PuntoResource;
use App\Models\AuditLog;
use App\Models\Punto;
use App\Services\PuntoTelemetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PuntoController extends Controller
{
    /**
     * Lista todos los puntos de reciclaje para el administrador (activos primero).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Punto::query()
            ->orderByDesc('activo')
            ->orderByDesc('porcentaje_llenado')
            ->orderBy('nombre');

        if ($request->boolean('solo_activos')) {
            $query->where('activo', true);
        }

        return PuntoResource::collection($query->get());
    }

    /**
     * Lista solo puntos activos en modo lectura para el chofer.
     */
    public function indexChofer(): AnonymousResourceCollection
    {
        $puntos = Punto::where('activo', true)
            ->orderByDesc('porcentaje_llenado')
            ->orderBy('nombre')
            ->get();

        return PuntoResource::collection($puntos);
    }

    /**
     * Detalle de un punto incluyendo sus últimas 15 lecturas de llenado.
     */
    public function show(Punto $punto): PuntoResource
    {
        $punto->load(['lecturas' => fn ($q) => $q->limit(15)]);

        return new PuntoResource($punto);
    }

    /**
     * Crea un punto de reciclaje y registra su lectura inicial si se entrega nivel.
     */
    public function store(StorePuntoRequest $request, PuntoTelemetryService $telemetry): JsonResponse
    {
        $datos = $request->validated();
        $porcentaje = (int) ($datos['porcentaje_llenado'] ?? 0);
        $altura = (float) ($datos['altura_contenedor_cm'] ?? 120.0);

        $punto = DB::transaction(function () use ($datos, $porcentaje, $altura, $request, $telemetry): Punto {
            $creado = Punto::create([
                'nombre' => $datos['nombre'],
                'nombre_automatico' => (bool) ($datos['nombre_automatico'] ?? true),
                'direccion' => $datos['direccion'],
                'tipo_punto' => $datos['tipo_punto'],
                'latitud' => $datos['latitud'],
                'longitud' => $datos['longitud'],
                'porcentaje_llenado' => $porcentaje,
                'estado_llenado' => Punto::clasificarEstado($porcentaje),
                'origen_actualizacion' => Punto::ORIGEN_MANUAL,
                'ultima_actualizacion_estado' => now(),
                'altura_contenedor_cm' => $altura,
                'sensor_codigo' => $datos['sensor_codigo'] ?? null,
                'activo' => true,
            ]);

            $telemetry->registrarLectura(
                punto: $creado,
                origen: Punto::ORIGEN_MANUAL,
                porcentajeDirecto: $porcentaje,
                registradaPor: $request->user(),
            );

            AuditLog::registrar('punto_creado', $request->user(), $request, [
                'punto_id' => $creado->id,
                'nombre' => $creado->nombre,
                'tipo_punto' => $creado->tipo_punto,
            ]);

            return $creado;
        });

        $punto->refresh()->load(['lecturas' => fn ($q) => $q->limit(15)]);

        return (new PuntoResource($punto))->response()->setStatusCode(201);
    }

    /**
     * Edita datos, coordenadas, calibración o nivel manual de un punto.
     */
    public function update(UpdatePuntoRequest $request, Punto $punto, PuntoTelemetryService $telemetry): PuntoResource
    {
        $datos = $request->validated();
        $porcentajeNuevo = array_key_exists('porcentaje_llenado', $datos)
            ? (int) $datos['porcentaje_llenado']
            : null;
        unset($datos['porcentaje_llenado']);

        DB::transaction(function () use ($punto, $datos, $porcentajeNuevo, $request, $telemetry): void {
            if ($datos !== []) {
                $punto->update($datos);
            }

            if ($porcentajeNuevo !== null) {
                $telemetry->registrarLectura(
                    punto: $punto,
                    origen: Punto::ORIGEN_MANUAL,
                    porcentajeDirecto: $porcentajeNuevo,
                    registradaPor: $request->user(),
                );
            }

            AuditLog::registrar('punto_actualizado', $request->user(), $request, [
                'punto_id' => $punto->id,
                'campos' => array_keys($request->validated()),
            ]);
        });

        return new PuntoResource($punto->fresh()->load(['lecturas' => fn ($q) => $q->limit(15)]));
    }

    /**
     * Baja lógica del punto (activo = false).
     */
    public function destroy(Request $request, Punto $punto): PuntoResource
    {
        $punto->update(['activo' => false]);

        AuditLog::registrar('punto_desactivado', $request->user(), $request, [
            'punto_id' => $punto->id,
            'nombre' => $punto->nombre,
        ]);

        return new PuntoResource($punto->fresh());
    }

    /**
     * Reactiva un punto desactivado (activo = true).
     */
    public function reactivar(Request $request, Punto $punto): PuntoResource
    {
        $punto->update(['activo' => true]);

        AuditLog::registrar('punto_reactivado', $request->user(), $request, [
            'punto_id' => $punto->id,
            'nombre' => $punto->nombre,
        ]);

        return new PuntoResource($punto->fresh());
    }

    /**
     * Genera o rota el token Bearer del sensor ESP32 (devuelto en plano UNA sola vez).
     */
    public function generarTokenSensor(Request $request, Punto $punto): JsonResponse
    {
        $tokenPlano = 'eco_iot_'.Str::random(40);
        $codigo = is_string($punto->sensor_codigo) && trim($punto->sensor_codigo) !== ''
            ? $punto->sensor_codigo
            : sprintf('ESP32-P%03d', $punto->id);

        $punto->update([
            'sensor_codigo' => $codigo,
            'sensor_token_hash' => Punto::hashSensorToken($tokenPlano),
        ]);

        AuditLog::registrar('punto_sensor_token_generado', $request->user(), $request, [
            'punto_id' => $punto->id,
            'sensor_codigo' => $codigo,
        ]);

        return response()->json([
            'data' => [
                'token' => $tokenPlano,
                'sensor_codigo' => $codigo,
                'endpoint_iot' => '/api/v1/iot/lecturas',
                'ejemplo_payload' => [
                    'distancia_cm' => 30.0,
                    'bateria_voltaje' => 3.92,
                    'lectura_uuid' => Str::uuid()->toString(),
                    'medido_en' => now()->toIso8601String(),
                ],
                'punto' => (new PuntoResource($punto->fresh()))->resolve(),
            ],
        ]);
    }

    /**
     * Permite al administrador simular una lectura IoT pasando por el mismo
     * cálculo ultrasónico real del ESP32 para probar calibración y estados.
     */
    public function simularLecturaIot(
        StoreIotLecturaRequest $request,
        Punto $punto,
        PuntoTelemetryService $telemetry,
    ): JsonResponse {
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
            registradaPor: $request->user(),
        );

        $puntoActualizado = $resultado['punto']->load(['lecturas' => fn ($q) => $q->limit(15)]);

        return response()->json([
            'data' => (new PuntoResource($puntoActualizado))->resolve(),
            'lectura' => (new LecturaPuntoResource($resultado['lectura']))->resolve(),
            'duplicada' => $resultado['duplicada'],
        ], $resultado['duplicada'] ? 200 : 201);
    }
}
