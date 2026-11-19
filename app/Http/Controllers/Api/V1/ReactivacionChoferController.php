<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chofer\RechazarSolicitudReactivacionRequest;
use App\Http\Requests\Chofer\StoreSolicitudReactivacionRequest;
use App\Http\Resources\SolicitudReactivacionChoferResource;
use App\Models\AuditLog;
use App\Models\Chofer;
use App\Models\SolicitudReactivacionChofer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ReactivacionChoferController extends Controller
{
    /**
     * Devuelve el estado de la cuenta del chofer autenticado (incluso inactivo)
     * y su solicitud de reactivación del ciclo vigente.
     */
    public function estadoCuenta(Request $request): JsonResponse
    {
        $user = $request->user();
        $chofer = $user?->chofer()
            ->with('ultimaSolicitudReactivacion.resueltaPor.administrador')
            ->first();

        if (! $chofer instanceof Chofer) {
            return response()->json(['message' => 'No existe perfil de chofer asociado a esta cuenta.'], 404);
        }

        $solicitud = $user->is_active ? null : $chofer->solicitudReactivacionCicloActual();
        $puedeSolicitar = ! $user->is_active && $solicitud === null;

        return response()->json([
            'data' => [
                'is_active' => (bool) $user->is_active,
                'motivo_desactivacion' => $user->is_active ? null : $chofer->motivo_desactivacion,
                'desactivado_en' => $user->is_active ? null : $chofer->desactivado_en?->toIso8601String(),
                'puede_solicitar_reactivacion' => $puedeSolicitar,
                'solicitud_reactivacion' => $solicitud
                    ? SolicitudReactivacionChoferResource::resumen($solicitud)
                    : null,
            ],
        ]);
    }

    /**
     * Registra una solicitud de reactivación para el chofer desactivado.
     * Solo se permite una solicitud por ciclo de desactivación.
     */
    public function solicitar(StoreSolicitudReactivacionRequest $request): JsonResponse
    {
        $user = $request->user();
        $chofer = $user?->chofer()->first();

        if (! $chofer instanceof Chofer) {
            return response()->json(['message' => 'No existe perfil de chofer asociado a esta cuenta.'], 404);
        }

        if ($user->is_active) {
            return response()->json(['message' => 'Tu cuenta ya se encuentra activa.'], 422);
        }

        $ciclo = max(1, (int) $chofer->ciclo_desactivacion);
        if ((int) $chofer->ciclo_desactivacion === 0) {
            $chofer->update(['ciclo_desactivacion' => $ciclo]);
        }

        $existente = $chofer->solicitudesReactivacion()
            ->where('ciclo_desactivacion', $ciclo)
            ->latest('id')
            ->first();

        if ($existente instanceof SolicitudReactivacionChofer) {
            if ($existente->estado === SolicitudReactivacionChofer::ESTADO_PENDIENTE) {
                return response()->json([
                    'message' => 'Ya tienes una solicitud de reactivación pendiente de revisión.',
                ], 409);
            }

            if ($existente->estado === SolicitudReactivacionChofer::ESTADO_RECHAZADA) {
                return response()->json([
                    'message' => 'Tu solicitud de reactivación ya fue rechazada en este ciclo de desactivación.',
                ], 409);
            }
        }

        $motivo = $request->validated('motivo');

        $solicitud = DB::transaction(function () use ($chofer, $ciclo, $motivo, $request): SolicitudReactivacionChofer {
            $nueva = $chofer->solicitudesReactivacion()->create([
                'ciclo_desactivacion' => $ciclo,
                'motivo_desactivacion' => $chofer->motivo_desactivacion,
                'motivo' => $motivo,
                'estado' => SolicitudReactivacionChofer::ESTADO_PENDIENTE,
            ]);

            AuditLog::registrar('reactivacion_solicitada', $request->user(), $request, [
                'chofer_id' => $chofer->id,
                'solicitud_id' => $nueva->id,
                'ciclo_desactivacion' => $ciclo,
                'motivo' => $motivo,
            ]);

            return $nueva;
        });

        return (new SolicitudReactivacionChoferResource($solicitud->load('resueltaPor.administrador')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Lista las solicitudes de reactivación para el panel de notificaciones del administrador.
     */
    public function listarNotificaciones(): AnonymousResourceCollection
    {
        $solicitudes = SolicitudReactivacionChofer::with(['chofer.user', 'resueltaPor.administrador'])
            ->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [SolicitudReactivacionChofer::ESTADO_PENDIENTE])
            ->orderByDesc('created_at')
            ->get();

        $pendientes = $solicitudes
            ->where('estado', SolicitudReactivacionChofer::ESTADO_PENDIENTE)
            ->count();

        return SolicitudReactivacionChoferResource::collection($solicitudes)->additional([
            'meta' => [
                'pendientes_count' => $pendientes,
            ],
        ]);
    }

    /**
     * Aprueba una solicitud y reactiva inmediatamente la cuenta del chofer.
     */
    public function aprobar(Request $request, SolicitudReactivacionChofer $solicitud): SolicitudReactivacionChoferResource|JsonResponse
    {
        if ($solicitud->estado !== SolicitudReactivacionChofer::ESTADO_PENDIENTE) {
            return response()->json(['message' => 'Esta solicitud ya fue resuelta.'], 409);
        }

        DB::transaction(function () use ($solicitud, $request): void {
            $solicitud->update([
                'estado' => SolicitudReactivacionChofer::ESTADO_APROBADA,
                'resuelta_por_user_id' => $request->user()->id,
                'resuelta_en' => now(),
            ]);

            $chofer = $solicitud->chofer;
            $chofer->user->update(['is_active' => true]);
            $chofer->update([
                'motivo_desactivacion' => null,
                'desactivado_en' => null,
                'desactivado_por_user_id' => null,
            ]);

            AuditLog::registrar('reactivacion_aprobada', $request->user(), $request, [
                'solicitud_id' => $solicitud->id,
                'chofer_id' => $chofer->id,
                'ciclo_desactivacion' => $solicitud->ciclo_desactivacion,
            ]);
        });

        return new SolicitudReactivacionChoferResource(
            $solicitud->fresh()->load(['chofer.user', 'resueltaPor.administrador'])
        );
    }

    /**
     * Rechaza una solicitud de reactivación con motivo obligatorio.
     */
    public function rechazar(RechazarSolicitudReactivacionRequest $request, SolicitudReactivacionChofer $solicitud): SolicitudReactivacionChoferResource|JsonResponse
    {
        if ($solicitud->estado !== SolicitudReactivacionChofer::ESTADO_PENDIENTE) {
            return response()->json(['message' => 'Esta solicitud ya fue resuelta.'], 409);
        }

        $motivoRechazo = $request->validated('motivo_rechazo');

        DB::transaction(function () use ($solicitud, $motivoRechazo, $request): void {
            $solicitud->update([
                'estado' => SolicitudReactivacionChofer::ESTADO_RECHAZADA,
                'resuelta_por_user_id' => $request->user()->id,
                'resuelta_en' => now(),
                'motivo_rechazo' => $motivoRechazo,
            ]);

            AuditLog::registrar('reactivacion_rechazada', $request->user(), $request, [
                'solicitud_id' => $solicitud->id,
                'chofer_id' => $solicitud->chofer_id,
                'ciclo_desactivacion' => $solicitud->ciclo_desactivacion,
                'motivo_rechazo' => $motivoRechazo,
            ]);
        });

        return new SolicitudReactivacionChoferResource(
            $solicitud->fresh()->load(['chofer.user', 'resueltaPor.administrador'])
        );
    }
}
