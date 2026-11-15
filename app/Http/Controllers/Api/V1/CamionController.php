<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Camion\StoreCamionRequest;
use App\Http\Requests\Camion\UpdateCamionRequest;
use App\Http\Resources\CamionResource;
use App\Models\Camion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CamionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CamionResource::collection(Camion::with('choferActual')->orderBy('patente')->get());
    }

    public function store(StoreCamionRequest $request): JsonResponse
    {
        $camion = Camion::create($request->validated());

        return (new CamionResource($camion->refresh()->load('choferActual')))->response()->setStatusCode(201);
    }

    public function show(Camion $camion): CamionResource
    {
        return new CamionResource($camion->load('choferActual'));
    }

    public function update(UpdateCamionRequest $request, Camion $camion): CamionResource
    {
        $camion->update($request->validated());

        return new CamionResource($camion->load('choferActual'));
    }

    /**
     * Desactiva el camión (estado inactivo) y libera su chofer; no se permite si está en ruta.
     */
    public function destroy(Camion $camion): Response|JsonResponse
    {
        if ($camion->estado_operativo === 'en_ruta') {
            return response()->json(['message' => 'No se puede desactivar un camión en ruta.'], 409);
        }

        $camion->update(['estado_operativo' => 'inactivo', 'chofer_actual_id' => null]);

        return response()->noContent();
    }
}
