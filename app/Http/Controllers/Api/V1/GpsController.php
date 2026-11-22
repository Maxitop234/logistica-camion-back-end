<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gps\StoreGpsPingRequest;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\Ruta;
use App\Models\RutaPunto;
use App\Models\TelemetriaGps;
use App\Services\GpsTelemetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GpsController extends Controller
{
    public function __construct(
        private readonly GpsTelemetryService $gpsService,
    ) {}

    /**
     * Recibe un ping GPS o un lote de pings enviados por el chofer.
     */
    public function ping(StoreGpsPingRequest $request): JsonResponse
    {
        $chofer = $request->user()?->chofer()->first();
        if (! $chofer instanceof Chofer) {
            return response()->json([
                'message' => 'Tu cuenta no tiene un perfil de chofer asociado.',
            ], 403);
        }

        $pings = $request->pingsNormalizados();

        $contieneSimulado = collect($pings)->contains(
            static fn (array $p): bool => (bool) ($p['simulado'] ?? false)
        );
        if ($contieneSimulado && ! config('app.demo_mode')) {
            return response()->json([
                'message' => 'Las posiciones GPS simuladas están bloqueadas fuera del modo demostración.',
            ], 403);
        }

        $resultado = $this->gpsService->registrarPings($chofer, $pings);

        if ($resultado['aceptados'] === 0 && $resultado['duplicados'] === 0 && $resultado['rechazados'] > 0) {
            return response()->json([
                'message' => 'El GPS solo puede registrarse dentro del horario de una ruta en curso.',
                'data' => $resultado,
            ], 409);
        }

        $status = $resultado['aceptados'] > 0 ? 201 : 200;

        return response()->json([
            'message' => $resultado['aceptados'] > 0
                ? 'Telemetría GPS registrada.'
                : 'Pings ya registrados previamente.',
            'data' => $resultado,
        ], $status);
    }

    /**
     * Lista la flota activa con su última posición GPS real, señal y progreso de ruta.
     */
    public function flota(): JsonResponse
    {
        $camiones = Camion::query()
            ->where('estado_operativo', '!=', 'inactivo')
            ->with('choferActual')
            ->orderByRaw("CASE WHEN estado_operativo = 'en_ruta' THEN 0 ELSE 1 END")
            ->orderBy('patente')
            ->get();

        $rutasActivas = Ruta::query()
            ->where('estado', Ruta::ESTADO_EN_CURSO)
            ->with(['paradas', 'chofer'])
            ->get()
            ->keyBy('camion_id');

        $ahora = now();
        $items = $camiones->map(function (Camion $camion) use ($rutasActivas, $ahora): array {
            /** @var Ruta|null $ruta */
            $ruta = $rutasActivas->get($camion->id);
            $chofer = $ruta instanceof Ruta ? $ruta->chofer : $camion->choferActual;

            $ultimaAct = $camion->ultima_actualizacion_gps;
            $antiguedad = $ultimaAct
                ? max(0, (int) $ultimaAct->diffInSeconds($ahora, false))
                : null;
            $estadoSenal = $this->gpsService->estadoSenal($ultimaAct);

            $paradasTotales = $ruta ? $ruta->paradas->count() : 0;
            $paradasAtendidas = $ruta
                ? $ruta->paradas->where('estado_parada', RutaPunto::ESTADO_RECOLECTADO)->count()
                : 0;

            $tienePosicion = $camion->ultima_latitud !== null && $camion->ultima_longitud !== null;

            return [
                'id' => $camion->id,
                'patente' => $camion->patente,
                'marca' => $camion->marca,
                'modelo' => $camion->modelo,
                'estado_operativo' => $camion->estado_operativo,
                'en_ruta' => $ruta !== null || $camion->estado_operativo === 'en_ruta',
                'chofer' => $chofer ? [
                    'id' => $chofer->id,
                    'nombre' => $chofer->nombre_cifrado,
                    'avatar' => $chofer->avatarResuelto(),
                ] : null,
                'posicion' => $tienePosicion ? [
                    'latitud' => (float) $camion->ultima_latitud,
                    'longitud' => (float) $camion->ultima_longitud,
                ] : null,
                'velocidad_kmh' => (float) ($camion->velocidad_actual_kmh ?? 0),
                'ultima_actualizacion_gps' => $ultimaAct?->toIso8601String(),
                'antiguedad_segundos' => $antiguedad,
                'estado_senal' => $estadoSenal,
                'ruta_activa' => $ruta ? [
                    'id' => $ruta->id,
                    'estado' => $ruta->estado,
                    'paradas_atendidas' => $paradasAtendidas,
                    'paradas_totales' => $paradasTotales,
                    'distancia_estimada_km' => (float) $ruta->distancia_estimada_km,
                    'distancia_real_km' => (float) ($ruta->distancia_real_km ?? 0),
                    'hora_inicio_real' => $ruta->hora_inicio_real?->toIso8601String(),
                ] : null,
            ];
        })->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'actualizado_en' => $ahora->toIso8601String(),
                'en_ruta_count' => $items->where('en_ruta', true)->count(),
                'total_count' => $items->count(),
            ],
        ]);
    }

    /**
     * Historial ordenado de coordenadas GPS recorridas en una ruta para
     * contrastar contra la polilínea planificada.
     */
    public function traza(Request $request, Ruta $ruta): JsonResponse
    {
        $ruta->load(['camion', 'chofer', 'telemetriaGps']);

        $puntos = $ruta->telemetriaGps->map(static fn (TelemetriaGps $p): array => [
            'latitud' => $p->latitud,
            'longitud' => $p->longitud,
            'velocidad_kmh' => $p->velocidad_kmh,
            'precision_m' => $p->precision_m,
            'es_simulado' => $p->es_simulado,
            'capturado_en' => $p->capturado_en->toIso8601String(),
        ])->values();

        return response()->json([
            'data' => [
                'ruta_id' => $ruta->id,
                'estado' => $ruta->estado,
                'camion' => $ruta->camion ? [
                    'id' => $ruta->camion->id,
                    'patente' => $ruta->camion->patente,
                ] : null,
                'chofer' => $ruta->chofer ? [
                    'id' => $ruta->chofer->id,
                    'nombre' => $ruta->chofer->nombre_cifrado,
                ] : null,
                'distancia_estimada_km' => (float) $ruta->distancia_estimada_km,
                'distancia_real_km' => (float) ($ruta->distancia_real_km ?? 0),
                'encoded_polyline' => $ruta->encoded_polyline,
                'hora_inicio_real' => $ruta->hora_inicio_real?->toIso8601String(),
                'hora_termino_real' => $ruta->hora_termino_real?->toIso8601String(),
                'puntos' => $puntos,
            ],
        ]);
    }
}
