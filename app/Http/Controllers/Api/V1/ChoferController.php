<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chofer\StoreChoferRequest;
use App\Http\Requests\Chofer\UpdateChoferRequest;
use App\Http\Resources\ChoferResource;
use App\Models\Chofer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
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
     * Desactiva la cuenta (is_active = false), revoca sus tokens y libera su camión.
     */
    public function destroy(Chofer $chofer): Response
    {
        DB::transaction(function () use ($chofer): void {
            $chofer->user->update(['is_active' => false]);
            $chofer->user->tokens()->delete();
            $chofer->update(['disponible' => false]);
            $chofer->camionActual()->update(['chofer_actual_id' => null]);
        });

        return response()->noContent();
    }
}
