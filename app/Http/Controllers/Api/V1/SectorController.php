<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sector\StoreSectorRequest;
use App\Http\Requests\Sector\UpdateSectorRequest;
use App\Http\Resources\SectorResource;
use App\Models\AuditLog;
use App\Models\Sector;
use App\Services\Puntos\VigenciaDeEstado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SectorController extends Controller
{
    /**
     * Lista los sectores con sus puntos cargados frente a los esperados.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['activo' => ['sometimes', 'in:0,1,true,false']]);

        $query = $this->conConteos(Sector::query())->orderBy('nombre');

        if ($request->has('activo')) {
            $query->where('activo', $request->boolean('activo'));
        }

        return SectorResource::collection($query->get());
    }

    public function store(StoreSectorRequest $request): JsonResponse
    {
        $sector = Sector::create($request->datosSector());

        AuditLog::registrar('sector_creado', $request->user(), $request, [
            'sector_id' => $sector->id,
            'nombre' => $sector->nombre,
        ]);

        return (new SectorResource($this->recargar($sector)))->response()->setStatusCode(201);
    }

    public function show(Sector $sector): SectorResource
    {
        return new SectorResource($this->recargar($sector));
    }

    /**
     * Edita el sector. Si cambian los días de recolección, se recalcula la
     * vigencia del estado de sus puntos (ADR-0013).
     */
    public function update(UpdateSectorRequest $request, Sector $sector, VigenciaDeEstado $vigencia): SectorResource
    {
        $datos = $request->datosSector();
        $sector->update($datos);

        $recalculados = $sector->wasChanged('dias_recoleccion') ? $vigencia->recalcularSector($sector) : 0;

        AuditLog::registrar('sector_actualizado', $request->user(), $request, [
            'sector_id' => $sector->id,
            'campos' => array_keys($datos),
            'puntos_recalculados' => $recalculados,
        ]);

        return new SectorResource($this->recargar($sector));
    }

    /**
     * Baja lógica (`activo = false`). No se permite si el sector tiene puntos activos.
     */
    public function destroy(Request $request, Sector $sector): SectorResource|JsonResponse
    {
        $puntosActivos = $sector->puntos()->where('activo', true)->count();
        if ($puntosActivos > 0) {
            return response()->json([
                'message' => "El sector tiene {$puntosActivos} punto(s) activo(s). Muévelos a otro sector o desactívalos antes de desactivar el sector.",
            ], 409);
        }

        $sector->update(['activo' => false]);

        AuditLog::registrar('sector_desactivado', $request->user(), $request, [
            'sector_id' => $sector->id,
            'nombre' => $sector->nombre,
        ]);

        return new SectorResource($this->recargar($sector));
    }

    /**
     * @param  Builder<Sector>  $query
     * @return Builder<Sector>
     */
    private function conConteos(Builder $query): Builder
    {
        return $query->withCount([
            'puntos',
            'puntos as puntos_activos_count' => fn (Builder $q) => $q->where('activo', true),
        ]);
    }

    private function recargar(Sector $sector): Sector
    {
        return $this->conConteos(Sector::query())->findOrFail($sector->id);
    }
}
