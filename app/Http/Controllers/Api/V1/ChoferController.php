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
use App\Models\NotificacionChofer;
use App\Models\Ruta;
use App\Models\SolicitudReactivacionChofer;
use App\Models\User;
use App\Services\Cuentas\GestorDeCuentas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ChoferController extends Controller
{
    public function __construct(private readonly GestorDeCuentas $cuentas) {}

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
                'debe_cambiar_password' => (bool) ($datos['debe_cambiar_password'] ?? true),
                'password_cambiada_en' => now(),
            ]);

            return $user->chofer()->create([
                'nombre_cifrado' => $datos['nombre'],
                'rut_hash' => Chofer::hashRut($datos['rut']),
                'licencia_clase' => $datos['licencia_clase'] ?? 'A4',
                'telefono_cifrado' => $datos['telefono'] ?? null,
            ]);
        });

        AuditLog::registrar('chofer_creado', $request->user(), $request, [
            'chofer_id' => $chofer->id,
            'debe_cambiar_password' => (bool) ($datos['debe_cambiar_password'] ?? true),
        ]);

        return (new ChoferResource($chofer->fresh()->load(self::RELACIONES)))->response()->setStatusCode(201);
    }

    public function show(Chofer $chofer): ChoferResource
    {
        return new ChoferResource($chofer->load(self::RELACIONES));
    }

    public function update(UpdateChoferRequest $request, Chofer $chofer): ChoferResource|JsonResponse
    {
        $nuevoEmail = $request->validated('email');
        if ($nuevoEmail !== null && $chofer->user->esCuentaDemoProtegida() && $nuevoEmail !== $chofer->user->email) {
            return response()->json(['message' => GestorDeCuentas::MENSAJE_DEMO], 403);
        }

        $columnas = [
            'nombre' => 'nombre_cifrado',
            'telefono' => 'telefono_cifrado',
            'licencia_clase' => 'licencia_clase',
            'disponible' => 'disponible',
        ];

        $cambios = [];
        foreach ($request->safe()->except('email') as $campo => $valor) {
            $cambios[$columnas[$campo]] = $valor;
        }

        $chofer->update($cambios);

        if ($nuevoEmail !== null && $nuevoEmail !== $chofer->user->email) {
            $anterior = $chofer->user->email;
            $chofer->user->update(['email' => $nuevoEmail]);
            $this->cuentas->revocarTokens($chofer->user);
            AuditLog::registrar('chofer_email_cambiado', $request->user(), $request, [
                'chofer_id' => $chofer->id,
                'email_anterior' => $anterior,
                'email_nuevo' => $nuevoEmail,
                'sesiones_revocadas' => true,
            ]);
        }

        return new ChoferResource($chofer->load(self::RELACIONES));
    }

    /**
     * Restablece la contraseña del chofer: genera una temporal que se entrega
     * una sola vez, exige cambiarla al ingresar y cierra sus sesiones abiertas.
     */
    public function restablecerPassword(Request $request, Chofer $chofer): JsonResponse
    {
        if ($chofer->user->esCuentaDemoProtegida()) {
            return response()->json(['message' => GestorDeCuentas::MENSAJE_DEMO], 403);
        }

        $nueva = $this->cuentas->generarPassword();
        $this->cuentas->fijarPassword($chofer->user, $nueva, true);
        AuditLog::registrar('chofer_password_restablecida', $request->user(), $request, [
            'chofer_id' => $chofer->id,
            'sesiones_revocadas' => true,
        ]);

        return response()->json(['password' => $nueva, 'debe_cambiar_password' => true]);
    }

    /**
     * Desactiva la cuenta (is_active = false), guarda el motivo, inicia un
     * nuevo ciclo de desactivación, revoca tokens, libera su camión y audita.
     */
    public function destroy(DestroyChoferRequest $request, Chofer $chofer): Response|JsonResponse
    {
        if ($chofer->rutas()->where('estado', Ruta::ESTADO_EN_CURSO)->exists()) {
            return response()->json(['message' => 'No se puede desactivar al chofer: tiene una ruta en curso.'], 409);
        }

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

            NotificacionChofer::crear(
                $chofer,
                NotificacionChofer::TIPO_CUENTA_REACTIVADA,
                'Cuenta reactivada',
                'Tu cuenta fue reactivada por el administrador. Ya puedes volver a operar normalmente en la aplicación.',
            );
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
