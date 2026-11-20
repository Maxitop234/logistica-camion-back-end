<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chofer\DestroyChoferRequest;
use App\Http\Requests\Chofer\StoreChoferRequest;
use App\Http\Requests\Chofer\UpdateAvatarChoferRequest;
use App\Http\Requests\Chofer\UpdateChoferRequest;
use App\Http\Resources\ChoferResource;
use App\Models\AuditLog;
use App\Models\Chofer;
use App\Models\SolicitudReactivacionChofer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ChoferController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const RELACIONES = [
        'user',
        'camionActual',
        ...LicenciaChoferController::RELACIONES_LICENCIA,
        'ultimaSolicitudReactivacion.resueltaPor.administrador',
    ];

    public function index(): AnonymousResourceCollection
    {
        return ChoferResource::collection(Chofer::with(self::RELACIONES)->orderBy('id')->get());
    }

    /**
     * Crea en una sola transacción el usuario (rol chofer) y su perfil con datos cifrados.
     */
    public function store(StoreChoferRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $chofer = DB::transaction(function () use ($datos): Chofer {
            $user = User::create([
                'name' => 'Chofer',
                'email' => $datos['email'],
                'password' => $datos['password'],
                'role' => 'chofer',
            ]);

            return $user->chofer()->create([
                'nombre_cifrado' => $datos['nombre'],
                'rut_hash' => Chofer::hashRut($datos['rut']),
                'licencia_clase' => $datos['licencia_clase'] ?? 'A4',
                'telefono_cifrado' => $datos['telefono'] ?? null,
            ]);
        });

        return (new ChoferResource($chofer->fresh()->load(self::RELACIONES)))->response()->setStatusCode(201);
    }

    public function show(Chofer $chofer): ChoferResource
    {
        return new ChoferResource($chofer->load(self::RELACIONES));
    }

    public function update(UpdateChoferRequest $request, Chofer $chofer): ChoferResource
    {
        $columnas = [
            'nombre' => 'nombre_cifrado',
            'telefono' => 'telefono_cifrado',
            'licencia_clase' => 'licencia_clase',
            'disponible' => 'disponible',
        ];

        $cambios = [];
        foreach ($request->validated() as $campo => $valor) {
            $cambios[$columnas[$campo]] = $valor;
        }

        $chofer->update($cambios);

        return new ChoferResource($chofer->load(self::RELACIONES));
    }

    /**
     * Desactiva la cuenta (is_active = false), guarda el motivo, inicia un
     * nuevo ciclo de desactivación, revoca tokens, libera su camión y audita.
     */
    public function destroy(DestroyChoferRequest $request, Chofer $chofer): Response
    {
        $motivo = $request->validated('motivo') ?? 'Desactivado por el administrador';

        DB::transaction(function () use ($chofer, $motivo, $request): void {
            $nuevoCiclo = ((int) $chofer->ciclo_desactivacion) + 1;

            $chofer->user->update(['is_active' => false]);
            $chofer->user->tokens()->delete();
            $chofer->update([
                'disponible' => false,
                'motivo_desactivacion' => $motivo,
                'desactivado_en' => now(),
                'desactivado_por_user_id' => $request->user()->id,
                'ciclo_desactivacion' => $nuevoCiclo,
            ]);
            $chofer->camionActual()->update(['chofer_actual_id' => null]);

            AuditLog::registrar('chofer_desactivado', $request->user(), $request, [
                'chofer_id' => $chofer->id,
                'motivo' => $motivo,
                'ciclo_desactivacion' => $nuevoCiclo,
            ]);
        });

        return response()->noContent();
    }

    /**
     * Reactiva manualmente la cuenta del chofer en cualquier momento, limpia el
     * estado de desactivación/solicitudes pendientes del ciclo actual y audita.
     */
    public function reactivar(Request $request, Chofer $chofer): ChoferResource|JsonResponse
    {
        if ($chofer->user->is_active) {
            return response()->json(['message' => 'La cuenta del chofer ya está activa.'], 422);
        }

        DB::transaction(function () use ($chofer, $request): void {
            $chofer->user->update(['is_active' => true]);
            $chofer->update([
                'motivo_desactivacion' => null,
                'desactivado_en' => null,
                'desactivado_por_user_id' => null,
            ]);

            $chofer->solicitudesReactivacion()
                ->where('estado', SolicitudReactivacionChofer::ESTADO_PENDIENTE)
                ->update([
                    'estado' => SolicitudReactivacionChofer::ESTADO_APROBADA,
                    'resuelta_por_user_id' => $request->user()->id,
                    'resuelta_en' => now(),
                ]);

            AuditLog::registrar('chofer_reactivado', $request->user(), $request, [
                'chofer_id' => $chofer->id,
                'ciclo_desactivacion' => (int) $chofer->ciclo_desactivacion,
                'origen' => 'manual',
            ]);
        });

        return new ChoferResource($chofer->fresh()->load(self::RELACIONES));
    }

    /**
     * Actualiza la configuración visual del avatar del chofer autenticado.
     */
    public function actualizarAvatar(UpdateAvatarChoferRequest $request): JsonResponse
    {
        $chofer = $request->user()?->chofer;
        if (! $chofer instanceof Chofer) {
            return response()->json(['message' => 'Perfil de chofer no encontrado.'], 404);
        }

        $datos = $request->validated();
        $chofer->update([
            'avatar_config' => [
                'peinado' => (string) $datos['peinado'],
                'color_pelo' => (string) $datos['color_pelo'],
                'tono_piel' => (string) $datos['tono_piel'],
                'lentes' => (bool) $datos['lentes'],
            ],
        ]);

        return response()->json([
            'data' => $chofer->fresh()->avatarResuelto(),
        ]);
    }
}
