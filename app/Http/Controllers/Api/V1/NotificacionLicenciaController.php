<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Licencia\RechazarSolicitudBorradoRequest;
use App\Http\Resources\SolicitudBorradoLicenciaResource;
use App\Models\AuditLog;
use App\Models\SolicitudBorradoLicencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class NotificacionLicenciaController extends Controller
{
    /**
     * Lista las solicitudes de borrado de licencia (pendientes primero) con el conteo de pendientes.
     */
    public function index(): AnonymousResourceCollection
    {
        $solicitudes = SolicitudBorradoLicencia::with(['chofer.user', 'resueltaPor.administrador'])
            ->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [SolicitudBorradoLicencia::ESTADO_PENDIENTE])
            ->orderByDesc('created_at')
            ->get();

        $pendientes = $solicitudes
            ->where('estado', SolicitudBorradoLicencia::ESTADO_PENDIENTE)
            ->count();

        return SolicitudBorradoLicenciaResource::collection($solicitudes)->additional([
            'meta' => [
                'pendientes_count' => $pendientes,
            ],
        ]);
    }

    /**
     * Aprueba una solicitud y habilita el botón de borrado para ese chofer.
     */
    public function aprobar(Request $request, SolicitudBorradoLicencia $solicitud): SolicitudBorradoLicenciaResource|JsonResponse
    {
        if ($solicitud->estado !== SolicitudBorradoLicencia::ESTADO_PENDIENTE) {
            return response()->json(['message' => 'Esta solicitud ya fue resuelta.'], 409);
        }

        DB::transaction(function () use ($solicitud, $request): void {
            $solicitud->update([
                'estado' => SolicitudBorradoLicencia::ESTADO_APROBADA,
                'resuelta_por_user_id' => $request->user()->id,
                'resuelta_en' => now(),
            ]);

            $solicitud->chofer->update([
                'licencia_borrado_habilitado' => true,
            ]);

            AuditLog::registrar('licencia_borrado_aprobado', $request->user(), $request, [
                'solicitud_id' => $solicitud->id,
                'chofer_id' => $solicitud->chofer_id,
            ]);
        });

        return new SolicitudBorradoLicenciaResource(
            $solicitud->fresh()->load(['chofer.user', 'resueltaPor.administrador'])
        );
    }

    /**
     * Rechaza una solicitud manteniendo bloqueado el documento del chofer.
     */
    public function rechazar(RechazarSolicitudBorradoRequest $request, SolicitudBorradoLicencia $solicitud): SolicitudBorradoLicenciaResource|JsonResponse
    {
        if ($solicitud->estado !== SolicitudBorradoLicencia::ESTADO_PENDIENTE) {
            return response()->json(['message' => 'Esta solicitud ya fue resuelta.'], 409);
        }

        $respuesta = $request->validated('respuesta_admin');

        DB::transaction(function () use ($solicitud, $respuesta, $request): void {
            $solicitud->update([
                'estado' => SolicitudBorradoLicencia::ESTADO_RECHAZADA,
                'resuelta_por_user_id' => $request->user()->id,
                'resuelta_en' => now(),
                'respuesta_admin' => $respuesta,
            ]);

            AuditLog::registrar('licencia_borrado_rechazado', $request->user(), $request, [
                'solicitud_id' => $solicitud->id,
                'chofer_id' => $solicitud->chofer_id,
                'respuesta_admin' => $respuesta,
            ]);
        });

        return new SolicitudBorradoLicenciaResource(
            $solicitud->fresh()->load(['chofer.user', 'resueltaPor.administrador'])
        );
    }
}
