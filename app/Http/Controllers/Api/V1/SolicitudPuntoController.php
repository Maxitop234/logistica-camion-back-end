<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Punto\RechazarSolicitudPuntoRequest;
use App\Http\Requests\Punto\StoreSolicitudPuntoRequest;
use App\Http\Resources\SolicitudPuntoResource;
use App\Models\AuditLog;
use App\Models\Chofer;
use App\Models\NotificacionChofer;
use App\Models\Punto;
use App\Models\SolicitudPunto;
use App\Services\Puntos\AsignadorDeSector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SolicitudPuntoController extends Controller
{
    /**
     * El chofer propone un nuevo punto de reciclaje ubicándolo en el mapa.
     */
    public function store(StoreSolicitudPuntoRequest $request): JsonResponse
    {
        $chofer = $request->user()?->chofer()->first();
        if (! $chofer instanceof Chofer) {
            return response()->json(['message' => 'No existe perfil de chofer asociado a esta cuenta.'], 404);
        }

        $datos = $request->validated();

        $solicitud = DB::transaction(function () use ($chofer, $datos, $request): SolicitudPunto {
            $nueva = SolicitudPunto::create([
                'chofer_id' => $chofer->id,
                'nombre' => $datos['nombre'],
                'nombre_automatico' => (bool) ($datos['nombre_automatico'] ?? true),
                'direccion' => $datos['direccion'],
                'tipo_punto' => $datos['tipo_punto'],
                'latitud' => $datos['latitud'],
                'longitud' => $datos['longitud'],
                'observacion' => $datos['observacion'] ?? null,
                'estado' => SolicitudPunto::ESTADO_PENDIENTE,
            ]);

            AuditLog::registrar('punto_propuesto_chofer', $request->user(), $request, [
                'chofer_id' => $chofer->id,
                'solicitud_id' => $nueva->id,
                'nombre' => $nueva->nombre,
            ]);

            return $nueva;
        });

        return (new SolicitudPuntoResource($solicitud->load(['chofer.user', 'resueltaPor.administrador'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Lista propuestas de puntos del administrador (pendientes primero).
     */
    public function index(): AnonymousResourceCollection
    {
        $solicitudes = SolicitudPunto::with(['chofer.user', 'resueltaPor.administrador'])
            ->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [SolicitudPunto::ESTADO_PENDIENTE])
            ->orderByDesc('created_at')
            ->get();

        $pendientes = $solicitudes
            ->where('estado', SolicitudPunto::ESTADO_PENDIENTE)
            ->count();

        return SolicitudPuntoResource::collection($solicitudes)->additional([
            'meta' => [
                'pendientes_count' => $pendientes,
            ],
        ]);
    }

    /**
     * Aprueba la propuesta, crea el punto en BD y notifica al chofer.
     *
     * El punto nace "sin medición" (ADR-0013). Si no se indica `sector_id`, se
     * le asigna el sector del punto activo más cercano y queda para revisión.
     */
    public function aprobar(Request $request, SolicitudPunto $solicitud, AsignadorDeSector $asignador): SolicitudPuntoResource|JsonResponse
    {
        $request->validate([
            'sector_id' => ['sometimes', 'nullable', 'integer', Rule::exists('sectores', 'id')->where('activo', true)],
        ], [
            'sector_id.exists' => 'El sector indicado no existe o está inactivo.',
        ]);

        if ($solicitud->estado !== SolicitudPunto::ESTADO_PENDIENTE) {
            return response()->json(['message' => 'Esta propuesta de punto ya fue resuelta.'], 409);
        }

        $sectorId = $request->filled('sector_id')
            ? $request->integer('sector_id')
            : $asignador->sectorMasCercano((float) $solicitud->latitud, (float) $solicitud->longitud)?->id;

        DB::transaction(function () use ($solicitud, $request, $sectorId): void {
            $punto = Punto::create([
                'nombre' => $solicitud->nombre,
                'nombre_automatico' => (bool) $solicitud->nombre_automatico,
                'direccion' => $solicitud->direccion,
                'tipo_punto' => $solicitud->tipo_punto,
                'sector_id' => $sectorId,
                'latitud' => $solicitud->latitud,
                'longitud' => $solicitud->longitud,
                'fuente_ubicacion' => Punto::FUENTE_MANUAL,
                'requiere_revision' => ! $request->filled('sector_id'),
                'nota' => $solicitud->observacion,
                'porcentaje_llenado' => null,
                'estado_llenado' => null,
                'origen_actualizacion' => Punto::ORIGEN_CHOFER,
                'ultima_actualizacion_estado' => null,
                'altura_contenedor_cm' => 120.0,
                'activo' => true,
            ]);

            $solicitud->update([
                'estado' => SolicitudPunto::ESTADO_APROBADA,
                'punto_creado_id' => $punto->id,
                'resuelta_por_user_id' => $request->user()->id,
                'resuelta_en' => now(),
            ]);

            AuditLog::registrar('punto_propuesta_aprobada', $request->user(), $request, [
                'solicitud_id' => $solicitud->id,
                'punto_id' => $punto->id,
                'chofer_id' => $solicitud->chofer_id,
            ]);

            NotificacionChofer::crear(
                $solicitud->chofer_id,
                NotificacionChofer::TIPO_PUNTO_APROBADO,
                'Punto de reciclaje aprobado',
                "Tu propuesta «{$solicitud->nombre}» fue aprobada y ya está visible en el mapa.",
            );
        });

        return new SolicitudPuntoResource(
            $solicitud->fresh()->load(['chofer.user', 'resueltaPor.administrador']),
        );
    }

    /**
     * Rechaza la propuesta con motivo obligatorio y notifica al chofer.
     */
    public function rechazar(RechazarSolicitudPuntoRequest $request, SolicitudPunto $solicitud): SolicitudPuntoResource|JsonResponse
    {
        if ($solicitud->estado !== SolicitudPunto::ESTADO_PENDIENTE) {
            return response()->json(['message' => 'Esta propuesta de punto ya fue resuelta.'], 409);
        }

        $motivo = (string) $request->validated('motivo_rechazo');

        DB::transaction(function () use ($solicitud, $motivo, $request): void {
            $solicitud->update([
                'estado' => SolicitudPunto::ESTADO_RECHAZADA,
                'resuelta_por_user_id' => $request->user()->id,
                'resuelta_en' => now(),
                'motivo_rechazo' => $motivo,
            ]);

            AuditLog::registrar('punto_propuesta_rechazada', $request->user(), $request, [
                'solicitud_id' => $solicitud->id,
                'chofer_id' => $solicitud->chofer_id,
                'motivo_rechazo' => $motivo,
            ]);

            NotificacionChofer::crear(
                $solicitud->chofer_id,
                NotificacionChofer::TIPO_PUNTO_RECHAZADO,
                'Propuesta de punto rechazada',
                "Tu propuesta «{$solicitud->nombre}» fue rechazada. Motivo: {$motivo}",
            );
        });

        return new SolicitudPuntoResource(
            $solicitud->fresh()->load(['chofer.user', 'resueltaPor.administrador']),
        );
    }
}
