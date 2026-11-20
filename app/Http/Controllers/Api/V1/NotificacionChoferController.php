<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificacionChoferResource;
use App\Models\Chofer;
use App\Models\NotificacionChofer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificacionChoferController extends Controller
{
    /**
     * Lista las notificaciones del chofer autenticado y el conteo de no leídas.
     */
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $chofer = $request->user()?->chofer()->first();
        if (! $chofer instanceof Chofer) {
            return response()->json(['message' => 'No existe perfil de chofer asociado a esta cuenta.'], 404);
        }

        $notificaciones = NotificacionChofer::where('chofer_id', $chofer->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $noLeidas = NotificacionChofer::where('chofer_id', $chofer->id)
            ->whereNull('leida_en')
            ->count();

        return NotificacionChoferResource::collection($notificaciones)->additional([
            'meta' => [
                'no_leidas_count' => $noLeidas,
            ],
        ]);
    }

    /**
     * Marca una notificación del chofer autenticado como leída.
     */
    public function marcarLeida(Request $request, NotificacionChofer $notificacion): NotificacionChoferResource|JsonResponse
    {
        $chofer = $request->user()?->chofer()->first();
        if (! $chofer instanceof Chofer) {
            return response()->json(['message' => 'No existe perfil de chofer asociado a esta cuenta.'], 404);
        }

        if ($notificacion->chofer_id !== $chofer->id) {
            return response()->json(['message' => 'No tienes permiso para acceder a esta notificación.'], 403);
        }

        if ($notificacion->leida_en === null) {
            $notificacion->update(['leida_en' => now()]);
        }

        return new NotificacionChoferResource($notificacion->fresh());
    }

    /**
     * Marca todas las notificaciones no leídas del chofer autenticado como leídas.
     */
    public function marcarTodasLeidas(Request $request): JsonResponse
    {
        $chofer = $request->user()?->chofer()->first();
        if (! $chofer instanceof Chofer) {
            return response()->json(['message' => 'No existe perfil de chofer asociado a esta cuenta.'], 404);
        }

        NotificacionChofer::where('chofer_id', $chofer->id)
            ->whereNull('leida_en')
            ->update(['leida_en' => now()]);

        return response()->json([
            'meta' => [
                'no_leidas_count' => 0,
            ],
        ]);
    }
}
