<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Punto\ListarPuntosRequest;
use App\Http\Requests\Punto\StoreIotLecturaRequest;
use App\Http\Requests\Punto\StorePuntoRequest;
use App\Http\Requests\Punto\UpdatePuntoRequest;
use App\Http\Resources\LecturaPuntoResource;
use App\Http\Resources\PuntoResource;
use App\Models\AuditLog;
use App\Models\Punto;
use App\Models\Ruta;
use App\Models\RutaPunto;
use App\Services\Iot\SimuladorDeSensores;
use App\Services\Puntos\AsignadorDeSector;
use App\Services\PuntoTelemetryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PuntoController extends Controller
{
    /**
     * Lista los puntos de reciclaje para el administrador (activos primero).
     *
     * Filtros: `estado_llenado` (lista; `sin_medicion` = nunca medido; por
     * defecto solo activos), `sector_id` (lista), `activo`, `con_dispositivo`,
     * `requiere_revision`, `estado_vigente` y `solo_activos`.
     */
    public function index(ListarPuntosRequest $request): AnonymousResourceCollection
    {
        SimuladorDeSensores::completarSiDemo();

        $query = Punto::query()
            ->orderByDesc('activo')
            ->orderByRaw('porcentaje_llenado IS NULL')
            ->orderByDesc('porcentaje_llenado')
            ->orderBy('nombre');

        $estados = $request->estados();
        if ($estados !== []) {
            $conocidos = array_values(array_diff($estados, [ListarPuntosRequest::SIN_MEDICION]));
            $incluyeSinMedicion = in_array(ListarPuntosRequest::SIN_MEDICION, $estados, true);
            $query->where(function (Builder $q) use ($conocidos, $incluyeSinMedicion): void {
                if ($conocidos !== []) {
                    $q->whereIn('estado_llenado', $conocidos);
                }
                if ($incluyeSinMedicion) {
                    $q->orWhereNull('estado_llenado');
                }
            });
        }

        if ($request->sectorIds() !== []) {
            $query->whereIn('sector_id', $request->sectorIds());
        }

        if ($request->has('activo')) {
            $query->where('activo', $request->boolean('activo'));
        } elseif ($request->boolean('solo_activos') || $estados !== []) {
            $query->where('activo', true);
        }

        if ($request->has('con_dispositivo')) {
            if ($request->boolean('con_dispositivo')) {
                $query->where(fn (Builder $q) => $q->whereNotNull('sensor_codigo')->orWhereNotNull('sensor_token_hash'));
            } else {
                $query->whereNull('sensor_codigo')->whereNull('sensor_token_hash');
            }
        }

        if ($request->has('requiere_revision')) {
            $query->where('requiere_revision', $request->boolean('requiere_revision'));
        }

        if ($request->has('estado_vigente')) {
            $ahora = now();
            if ($request->boolean('estado_vigente')) {
                $query->whereNotNull('estado_llenado')->where('estado_vigente_hasta', '>=', $ahora);
            } else {
                $query->where(fn (Builder $q) => $q->whereNull('estado_llenado')
                    ->orWhereNull('estado_vigente_hasta')
                    ->orWhere('estado_vigente_hasta', '<', $ahora));
            }
        }

        return PuntoResource::collection($query->get());
    }

    /**
     * Lista solo puntos activos en modo lectura para el chofer.
     */
    public function indexChofer(): AnonymousResourceCollection
    {
        SimuladorDeSensores::completarSiDemo();

        $puntos = Punto::where('activo', true)
            ->orderByRaw('porcentaje_llenado IS NULL')
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
     * Crea un punto de reciclaje. Sin `porcentaje_llenado` ni `estado_llenado`
     * queda "sin medición" (`estado_llenado = null`, ADR-0013). Sin sector, se
     * le asigna el del punto activo más cercano y queda marcado para revisión.
     */
    public function store(
        StorePuntoRequest $request,
        PuntoTelemetryService $telemetry,
        AsignadorDeSector $asignador,
    ): JsonResponse {
        $datos = $request->validated();
        $porcentaje = isset($datos['porcentaje_llenado']) ? (int) $datos['porcentaje_llenado'] : null;
        $estadoManual = $porcentaje === null && isset($datos['estado_llenado']) ? (string) $datos['estado_llenado'] : null;
        $altura = (float) ($datos['altura_contenedor_cm'] ?? 120.0);

        $sectorId = $request->sectorIndicado();
        $sectorSugerido = ! is_int($sectorId);
        if ($sectorSugerido) {
            $sectorId = $asignador->sectorMasCercano((float) $datos['latitud'], (float) $datos['longitud'])?->id;
        }

        $punto = DB::transaction(function () use ($datos, $porcentaje, $estadoManual, $altura, $sectorId, $sectorSugerido, $request, $telemetry): Punto {
            $creado = Punto::create([
                'nombre' => $datos['nombre'],
                'nombre_automatico' => (bool) ($datos['nombre_automatico'] ?? true),
                'direccion' => $datos['direccion'],
                'tipo_punto' => $datos['tipo_punto'],
                'sector_id' => $sectorId,
                'tipo_material' => $datos['tipo_material'] ?? null,
                'latitud' => $datos['latitud'],
                'longitud' => $datos['longitud'],
                'fuente_ubicacion' => Punto::FUENTE_MANUAL,
                'requiere_revision' => $sectorSugerido,
                'nota' => $datos['nota'] ?? null,
                'porcentaje_llenado' => null,
                'estado_llenado' => $estadoManual,
                'origen_actualizacion' => Punto::ORIGEN_MANUAL,
                'ultima_actualizacion_estado' => $estadoManual !== null ? now() : null,
                'altura_contenedor_cm' => $altura,
                'sensor_codigo' => $datos['sensor_codigo'] ?? null,
                'activo' => true,
            ]);

            if ($porcentaje !== null) {
                $telemetry->registrarLectura(
                    punto: $creado,
                    origen: Punto::ORIGEN_MANUAL,
                    porcentajeDirecto: $porcentaje,
                    registradaPor: $request->user(),
                );
            }

            AuditLog::registrar('punto_creado', $request->user(), $request, [
                'punto_id' => $creado->id,
                'nombre' => $creado->nombre,
                'tipo_punto' => $creado->tipo_punto,
                'sector_id' => $creado->sector_id,
                'sector_sugerido' => $sectorSugerido,
            ]);

            return $creado;
        });

        $punto->refresh()->load(['lecturas' => fn ($q) => $q->limit(15)]);

        return (new PuntoResource($punto))->response()->setStatusCode(201);
    }

    /**
     * Edita datos, sector, coordenadas, calibración o nivel manual de un punto.
     *
     * El ajuste manual del nivel queda con origen `manual` y la hora actual; si
     * llega el porcentaje, el estado se deriva de él. Mover el punto deja su
     * ubicación como `manual`.
     */
    public function update(UpdatePuntoRequest $request, Punto $punto, PuntoTelemetryService $telemetry): PuntoResource
    {
        $datos = $request->validated();
        $porcentajeNuevo = array_key_exists('porcentaje_llenado', $datos)
            ? (int) $datos['porcentaje_llenado']
            : null;
        $estadoNuevo = $porcentajeNuevo === null && array_key_exists('estado_llenado', $datos)
            ? (string) $datos['estado_llenado']
            : null;
        unset($datos['porcentaje_llenado'], $datos['estado_llenado'], $datos['sector'], $datos['sector_id']);

        $sectorId = $request->sectorIndicado();
        if ($sectorId !== false) {
            $datos['sector_id'] = $sectorId;
        }

        $mueveUbicacion = (array_key_exists('latitud', $datos) && (float) $datos['latitud'] !== (float) $punto->latitud)
            || (array_key_exists('longitud', $datos) && (float) $datos['longitud'] !== (float) $punto->longitud);
        if ($mueveUbicacion) {
            $datos['fuente_ubicacion'] = Punto::FUENTE_MANUAL;
        }

        DB::transaction(function () use ($punto, $datos, $porcentajeNuevo, $estadoNuevo, $request, $telemetry): void {
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
            } elseif ($estadoNuevo !== null) {
                $punto->update([
                    'estado_llenado' => $estadoNuevo,
                    'porcentaje_llenado' => null,
                    'origen_actualizacion' => Punto::ORIGEN_MANUAL,
                    'ultima_actualizacion_estado' => now(),
                ]);
            }

            AuditLog::registrar('punto_actualizado', $request->user(), $request, [
                'punto_id' => $punto->id,
                'campos' => array_keys($request->validated()),
            ]);
        });

        return new PuntoResource($punto->fresh()->load(['lecturas' => fn ($q) => $q->limit(15)]));
    }

    /**
     * Baja lógica del punto (activo = false). `409` si está en una ruta en curso.
     */
    public function destroy(Request $request, Punto $punto): PuntoResource|JsonResponse
    {
        $enRutaEnCurso = RutaPunto::query()
            ->where('punto_id', $punto->id)
            ->whereHas('ruta', fn (Builder $q) => $q->where('estado', Ruta::ESTADO_EN_CURSO))
            ->exists();

        if ($enRutaEnCurso) {
            return response()->json([
                'message' => 'No se puede desactivar el punto: está en una ruta en curso. Espera a que el chofer la finalice.',
            ], 409);
        }

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
     * Permite al administrador simular una lectura IoT pasando por el mismo
     * cálculo ultrasónico real del ESP32 para probar calibración y estados.
     * Solo existe con `APP_DEMO_MODE=true` (middleware `demo`).
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
