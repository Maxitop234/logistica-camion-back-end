<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Camion\DestroyCamionRequest;
use App\Http\Requests\Camion\StoreCamionRequest;
use App\Http\Requests\Camion\UpdateCamionRequest;
use App\Http\Resources\CamionResource;
use App\Models\AuditLog;
use App\Models\Camion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CamionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CamionResource::collection(Camion::with(['choferActual', 'rutaActiva'])->orderBy('patente')->get());
    }

    public function store(StoreCamionRequest $request): JsonResponse
    {
        $camion = Camion::create($request->validated());

        return (new CamionResource($camion->refresh()->load(['choferActual', 'rutaActiva'])))->response()->setStatusCode(201);
    }

    public function show(Camion $camion): CamionResource
    {
        return new CamionResource($camion->load(['choferActual', 'rutaActiva']));
    }

    public function update(UpdateCamionRequest $request, Camion $camion): CamionResource|JsonResponse
    {
        $datos = $request->validated();
        $estabaInactivo = $camion->estado_operativo === 'inactivo';
        $estadoNuevo = $datos['estado_operativo'] ?? $camion->estado_operativo;

        if ($camion->estado_operativo === 'en_ruta' && $estadoNuevo !== 'en_ruta') {
            return response()->json(['message' => 'No se puede cambiar el estado de un camión en ruta.'], 409);
        }
        $estaReactivando = $estabaInactivo && $estadoNuevo !== 'inactivo';

        DB::transaction(function () use ($camion, $datos, $estaReactivando, $estadoNuevo, $request): void {
            if ($estaReactivando) {
                $datos['motivo_desactivacion'] = null;
                $datos['desactivado_en'] = null;
                $datos['desactivado_por_user_id'] = null;
            }

            $camion->update($datos);

            if ($estaReactivando) {
                AuditLog::registrar('camion_reactivado', $request->user(), $request, [
                    'camion_id' => $camion->id,
                    'patente' => $camion->patente,
                    'estado_nuevo' => $estadoNuevo,
                ]);
            }
        });

        return new CamionResource($camion->fresh()->load(['choferActual', 'rutaActiva']));
    }

    /**
     * Desactiva el camión (estado inactivo), guarda motivo, libera su chofer y audita.
     * No se permite si está en ruta.
     */
    public function destroy(DestroyCamionRequest $request, Camion $camion): Response|JsonResponse
    {
        if ($camion->estado_operativo === 'en_ruta') {
            return response()->json(['message' => 'No se puede desactivar un camión en ruta.'], 409);
        }

        $motivo = $request->validated('motivo') ?? 'Desactivado por el administrador';

        DB::transaction(function () use ($camion, $motivo, $request): void {
            $camion->update([
                'estado_operativo' => 'inactivo',
                'chofer_actual_id' => null,
                'motivo_desactivacion' => $motivo,
                'desactivado_en' => now(),
                'desactivado_por_user_id' => $request->user()->id,
            ]);

            AuditLog::registrar('camion_desactivado', $request->user(), $request, [
                'camion_id' => $camion->id,
                'patente' => $camion->patente,
                'motivo' => $motivo,
            ]);
        });

        return response()->noContent();
    }
}
