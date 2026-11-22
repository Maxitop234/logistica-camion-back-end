<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ruta\GenerarRutaRequest;
use App\Http\Requests\Ruta\SincronizarLoteRequest;
use App\Http\Resources\RutaResource;
use App\Models\AuditLog;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\Punto;
use App\Models\Ruta;
use App\Models\RutaPunto;
use App\Services\Desvios\DetectorDeDesvios;
use App\Services\GpsTelemetryService;
use App\Services\OsmRouteOptimizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class RutaController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const RELACIONES = ['camion', 'chofer', 'paradas.punto'];

    /**
     * Lista rutas filtrables por fecha, estado, chofer o camión (administrador).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Ruta::with(self::RELACIONES)->orderByDesc('fecha_programada')->orderByDesc('id');

        if (is_string($request->query('fecha_programada')) && $request->query('fecha_programada') !== '') {
            $query->whereDate('fecha_programada', $request->query('fecha_programada'));
        }
        if (is_string($request->query('estado')) && $request->query('estado') !== '') {
            $query->where('estado', $request->query('estado'));
        }
        if ($request->filled('chofer_id')) {
            $query->where('chofer_id', (int) $request->query('chofer_id'));
        }
        if ($request->filled('camion_id')) {
            $query->where('camion_id', (int) $request->query('camion_id'));
        }

        return RutaResource::collection($query->get());
    }

    /**
     * Genera y persiste una ruta optimizada con OSRM (respaldo local si falla)
     * incluyendo solo puntos activos en estado `alto` o `lleno`.
     */
    public function generar(GenerarRutaRequest $request, OsmRouteOptimizerService $optimizer, GpsTelemetryService $gpsService): JsonResponse
    {
        $datos = $request->validated();

        /** @var Camion $camion */
        $camion = Camion::findOrFail($datos['camion_id']);
        if ($camion->estado_operativo !== 'disponible') {
            return response()->json(['message' => 'El camión seleccionado no está disponible.'], 422);
        }

        /** @var Chofer $chofer */
        $chofer = Chofer::with('user')->findOrFail($datos['chofer_id']);
        if (! $chofer->user->is_active || ! $chofer->disponible) {
            return response()->json(['message' => 'El chofer seleccionado no está disponible.'], 422);
        }

        if ($camion->chofer_actual_id !== null && $camion->chofer_actual_id !== $chofer->id) {
            return response()->json([
                'message' => 'El camión seleccionado ya tiene otro chofer asignado.',
            ], 422);
        }

        $otroCamion = Camion::where('chofer_actual_id', $chofer->id)->where('id', '!=', $camion->id)->exists();
        if ($otroCamion) {
            return response()->json([
                'message' => 'El chofer seleccionado ya tiene otro camión asignado.',
            ], 422);
        }

        $yaTieneRuta = Ruta::where('chofer_id', $chofer->id)
            ->whereDate('fecha_programada', $datos['fecha_programada'])
            ->whereIn('estado', [Ruta::ESTADO_PLANIFICADA, Ruta::ESTADO_EN_CURSO])
            ->exists();

        if ($yaTieneRuta) {
            return response()->json([
                'message' => 'Este chofer ya tiene una ruta planificada o en curso para la fecha seleccionada.',
            ], 422);
        }

        $puntosActivos = Punto::where('activo', true)->orderBy('id')->get();
        $listos = $puntosActivos->filter(fn (Punto $p): bool => $p->listoParaRecolectar())->values();
        $excluidos = $puntosActivos->count() - $listos->count();

        if ($listos->isEmpty()) {
            return response()->json([
                'message' => 'No hay puntos en estado alto o lleno para generar la ruta.',
            ], 422);
        }

        if (isset($datos['origen']['latitud'], $datos['origen']['longitud'])) {
            $origenLat = (float) $datos['origen']['latitud'];
            $origenLng = (float) $datos['origen']['longitud'];
        } else {
            [$origenLat, $origenLng] = $gpsService->origenParaChofer($chofer);
        }

        $optimizacion = $optimizer->optimizar($listos, $origenLat, $origenLng, now());

        $ruta = DB::transaction(function () use ($datos, $camion, $chofer, $puntosActivos, $listos, $excluidos, $optimizacion, $request): Ruta {
            $nueva = Ruta::create([
                'camion_id' => $camion->id,
                'chofer_id' => $chofer->id,
                'creado_por_admin_id' => $request->user()?->administrador?->id,
                'fecha_programada' => $datos['fecha_programada'],
                'estado' => Ruta::ESTADO_PLANIFICADA,
                'total_puntos_evaluados' => $puntosActivos->count(),
                'puntos_incluidos_count' => $listos->count(),
                'puntos_excluidos_count' => $excluidos,
                'distancia_estimada_km' => $optimizacion['distancia_km'],
                'duracion_estimada_min' => $optimizacion['duracion_min'],
                'encoded_polyline' => $optimizacion['encoded_polyline'],
            ]);

            foreach ($optimizacion['paradas'] as $parada) {
                $nueva->paradas()->create([
                    'punto_id' => $parada['punto']->id,
                    'orden_parada' => $parada['orden'],
                    'estado_llenado_inicial' => $parada['punto']->estado_llenado,
                    'estado_parada' => RutaPunto::ESTADO_PENDIENTE,
                    'distancia_tramo_km' => $parada['distancia_tramo_km'],
                    'duracion_tramo_min' => $parada['duracion_tramo_min'],
                    'hora_estimada_llegada' => $parada['hora_estimada_llegada'],
                ]);
            }

            AuditLog::registrar('route_generated', $request->user(), $request, [
                'ruta_id' => $nueva->id,
                'camion_id' => $camion->id,
                'chofer_id' => $chofer->id,
                'puntos_incluidos' => $listos->count(),
                'puntos_excluidos' => $excluidos,
                'fuente_ruteo' => $optimizacion['fuente'],
            ]);

            return $nueva;
        });

        return (new RutaResource($ruta->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Devuelve la ruta activa (`en_curso` o `planificada`) del chofer autenticado, o `data: null`.
     */
    public function rutaActiva(Request $request): JsonResponse|RutaResource
    {
        $chofer = $request->user()?->chofer()->first();
        if (! $chofer instanceof Chofer) {
            return response()->json(['data' => null]);
        }

        $ruta = Ruta::with(self::RELACIONES)
            ->where('chofer_id', $chofer->id)
            ->whereIn('estado', [Ruta::ESTADO_EN_CURSO, Ruta::ESTADO_PLANIFICADA])
            ->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [Ruta::ESTADO_EN_CURSO])
            ->orderByDesc('fecha_programada')
            ->orderByDesc('id')
            ->first();

        if (! $ruta instanceof Ruta) {
            return response()->json(['data' => null]);
        }

        return new RutaResource($ruta);
    }

    /**
     * Inicia una ruta planificada del chofer autenticado.
     */
    public function iniciar(Request $request, Ruta $ruta): RutaResource|JsonResponse
    {
        $chofer = $request->user()?->chofer()->first();
        if (! $chofer instanceof Chofer || $ruta->chofer_id !== $chofer->id) {
            return response()->json(['message' => 'No tienes permiso para iniciar esta ruta.'], 403);
        }

        if ($ruta->estado !== Ruta::ESTADO_PLANIFICADA) {
            return response()->json(['message' => 'Solo se pueden iniciar rutas en estado planificada.'], 422);
        }

        DB::transaction(function () use ($ruta, $request): void {
            $ruta->update([
                'estado' => Ruta::ESTADO_EN_CURSO,
                'hora_inicio_real' => now(),
            ]);

            $ruta->camion()->update(['estado_operativo' => 'en_ruta']);

            AuditLog::registrar('ruta_iniciada', $request->user(), $request, [
                'ruta_id' => $ruta->id,
                'camion_id' => $ruta->camion_id,
            ]);
        });

        return new RutaResource($ruta->fresh()->load(self::RELACIONES));
    }

    /**
     * Sincroniza un lote de recolecciones (idempotente por `sync_uuid`).
     */
    public function sincronizarLote(SincronizarLoteRequest $request, Ruta $ruta): RutaResource|JsonResponse
    {
        $chofer = $request->user()?->chofer()->first();
        if (! $chofer instanceof Chofer || $ruta->chofer_id !== $chofer->id) {
            return response()->json(['message' => 'No tienes permiso para registrar recolecciones en esta ruta.'], 403);
        }

        if ($ruta->estado !== Ruta::ESTADO_EN_CURSO) {
            return response()->json(['message' => 'La ruta debe estar en curso para registrar recolecciones.'], 422);
        }

        $recolecciones = $request->validated('recolecciones');

        DB::transaction(function () use ($ruta, $recolecciones): void {
            foreach ($recolecciones as $item) {
                $yaRegistrado = RutaPunto::where('sync_uuid', $item['sync_uuid'])->exists();
                if ($yaRegistrado) {
                    continue;
                }

                $parada = $ruta->paradas()
                    ->with('punto')
                    ->where('punto_id', $item['punto_id'])
                    ->first();

                if (! $parada instanceof RutaPunto) {
                    continue;
                }

                // Captura el % de llenado real justo antes de vaciar el punto (KPI 4);
                // null si el punto nunca fue medido (ADR-0013).
                $porcentajeAlVaciar = $parada->punto instanceof Punto && $parada->punto->porcentaje_llenado !== null
                    ? (int) $parada->punto->porcentaje_llenado
                    : null;

                $parada->update([
                    'estado_parada' => RutaPunto::ESTADO_RECOLECTADO,
                    'porcentaje_llenado_al_vaciar' => $porcentajeAlVaciar,
                    'hora_real_recoleccion' => Carbon::parse($item['hora_real_recoleccion']),
                    'sync_uuid' => $item['sync_uuid'],
                    'sincronizado_offline' => true,
                    'observaciones' => $item['observaciones'] ?? null,
                ]);

                // Vía el modelo, para recalcular la vigencia del estado (ADR-0013).
                $parada->punto?->update([
                    'estado_llenado' => Punto::ESTADO_VACIO,
                    'porcentaje_llenado' => 0,
                    'origen_actualizacion' => Punto::ORIGEN_RECOLECCION,
                    'ultima_actualizacion_estado' => now(),
                ]);
            }
        });

        return new RutaResource($ruta->fresh()->load(self::RELACIONES));
    }

    /**
     * Finaliza una ruta en curso y devuelve el camión a estado disponible.
     */
    public function finalizar(Request $request, Ruta $ruta, GpsTelemetryService $gpsService, DetectorDeDesvios $detector): RutaResource|JsonResponse
    {
        $chofer = $request->user()?->chofer()->first();
        if (! $chofer instanceof Chofer || $ruta->chofer_id !== $chofer->id) {
            return response()->json(['message' => 'No tienes permiso para finalizar esta ruta.'], 403);
        }

        if ($ruta->estado !== Ruta::ESTADO_EN_CURSO) {
            return response()->json(['message' => 'Solo se pueden finalizar rutas en curso.'], 422);
        }

        DB::transaction(function () use ($ruta, $request, $gpsService): void {
            $distanciaRealKm = $gpsService->recalcularDistanciaReal($ruta);

            $ruta->update([
                'estado' => Ruta::ESTADO_COMPLETADA,
                'hora_termino_real' => now(),
                'distancia_real_km' => $distanciaRealKm,
            ]);

            $ruta->camion()->update(['estado_operativo' => 'disponible']);

            AuditLog::registrar('ruta_finalizada', $request->user(), $request, [
                'ruta_id' => $ruta->id,
                'camion_id' => $ruta->camion_id,
                'distancia_real_km' => $distanciaRealKm,
            ]);
        });

        // Guarda los tramos en que el camión se salió de lo planificado; un fallo aquí no debe impedir cerrar la ruta.
        try {
            $detector->detectar($ruta->fresh());
        } catch (Throwable $e) {
            report($e);
        }

        return new RutaResource($ruta->fresh()->load(self::RELACIONES));
    }
}
