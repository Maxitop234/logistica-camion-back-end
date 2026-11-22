<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dispositivo\ActualizarDispositivoRequest;
use App\Http\Requests\Dispositivo\AprobarDispositivoRequest;
use App\Http\Resources\DispositivoResource;
use App\Models\AuditLog;
use App\Models\Dispositivo;
use App\Models\Punto;
use App\Services\Iot\IotException;
use App\Services\Iot\SimuladorDeSensores;
use App\Services\Iot\VinculacionDeDispositivos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DispositivoController extends Controller
{
    public function __construct(private readonly VinculacionDeDispositivos $vinculacion) {}

    /**
     * Lista los sensores. Filtro opcional `estado` (pendiente, activo, desactivado).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        SimuladorDeSensores::completarSiDemo();

        $query = Dispositivo::query()->with('punto')->orderBy('codigo_hardware');
        if (is_string($request->query('estado')) && $request->query('estado') !== '') {
            $query->where('estado', $request->query('estado'));
        }

        return DispositivoResource::collection($query->get());
    }

    /**
     * Detalle con lectura en vivo (el cliente refresca cada pocos segundos) e historial corto.
     */
    public function show(Dispositivo $dispositivo): JsonResponse
    {
        SimuladorDeSensores::completarSiDemo();

        return $this->respuesta($dispositivo->refresh(), true);
    }

    public function update(ActualizarDispositivoRequest $request, Dispositivo $dispositivo): JsonResponse
    {
        $datos = $request->validated();
        if (isset($datos['tipo_sensor']) && $datos['tipo_sensor'] !== $dispositivo->tipo_sensor && ! isset($datos['distancia_lleno_cm'])) {
            $datos['distancia_lleno_cm'] = (float) config("iot.sensores.{$datos['tipo_sensor']}.distancia_lleno_cm", 25);
        }
        $dispositivo->update($datos);
        $this->vinculacion->recalcularPunto($dispositivo);

        AuditLog::registrar('dispositivo_actualizado', $request->user(), $request, [
            'dispositivo_id' => $dispositivo->id,
            'campos' => array_keys($datos),
        ]);

        return $this->respuesta($dispositivo->refresh(), true);
    }

    /**
     * Aprueba un sensor anunciado: el administrador ingresa o escanea el código de la
     * etiqueta y elige el punto (existente o nuevo).
     */
    public function aprobar(AprobarDispositivoRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $punto = isset($datos['punto_id']) ? Punto::query()->whereKey($datos['punto_id'])->firstOrFail() : $datos['punto'];

        try {
            $dispositivo = $this->vinculacion->aprobar($datos['codigo_hardware'], $datos['codigo_vinculacion'], $punto, $request->user());
        } catch (IotException $e) {
            return response()->json(['message' => $e->getMessage()], $e->estado);
        }

        return $this->respuesta($dispositivo->refresh(), false, 201);
    }

    public function desvincular(Request $request, Dispositivo $dispositivo): JsonResponse
    {
        return $this->respuesta($this->vinculacion->desvincular($dispositivo, $request->user()), true);
    }

    public function mover(Request $request, Dispositivo $dispositivo): JsonResponse
    {
        $datos = $request->validate([
            'punto_id' => ['required', 'integer', Rule::exists('puntos', 'id')],
        ]);

        return $this->respuesta($this->vinculacion->mover($dispositivo, Punto::query()->whereKey($datos['punto_id'])->firstOrFail(), $request->user()), true);
    }

    public function desactivar(Request $request, Dispositivo $dispositivo): JsonResponse
    {
        return $this->respuesta($this->vinculacion->desactivar($dispositivo, $request->user()), true);
    }

    public function reactivar(Request $request, Dispositivo $dispositivo): JsonResponse
    {
        return $this->conErrores(fn () => $this->vinculacion->reactivar($dispositivo, $request->user()));
    }

    public function rotarToken(Request $request, Dispositivo $dispositivo): JsonResponse
    {
        return $this->conErrores(fn () => $this->vinculacion->rotarToken($dispositivo, $request->user()));
    }

    public function calibrarVacio(Request $request, Dispositivo $dispositivo): JsonResponse
    {
        return $this->conErrores(fn () => $this->vinculacion->calibrarVacio($dispositivo, $request->user()));
    }

    /**
     * Solo demostración: completa las lecturas que los sensores simulados no enviaron.
     */
    public function completarSimulador(SimuladorDeSensores $simulador): JsonResponse
    {
        return response()->json(['data' => $simulador->completarHasta(now(), true)]);
    }

    /**
     * @param  callable(): Dispositivo  $accion
     */
    private function conErrores(callable $accion): JsonResponse
    {
        try {
            return $this->respuesta($accion()->refresh(), true);
        } catch (IotException $e) {
            return response()->json(['message' => $e->getMessage()], $e->estado);
        }
    }

    private function respuesta(Dispositivo $dispositivo, bool $conHistorial, int $status = 200): JsonResponse
    {
        $dispositivo->load('punto');
        $datos = (new DispositivoResource($dispositivo))->resolve();
        if ($conHistorial) {
            $datos['lecturas'] = $this->historial($dispositivo);
        }

        return response()->json(['data' => $datos], $status);
    }

    /**
     * Últimas 15 lecturas del sensor, válidas e inválidas, de la más nueva a la más antigua.
     *
     * @return array<int, array<string, mixed>>
     */
    private function historial(Dispositivo $dispositivo): array
    {
        $validas = $dispositivo->lecturas()->limit(15)->get()->map(static fn ($l): array => [
            'valida' => true,
            'medido_en' => $l->medido_en?->toIso8601String(),
            'distancia_cm' => $l->distancia_cm !== null ? (float) $l->distancia_cm : null,
            'porcentaje_llenado' => (int) $l->porcentaje_llenado,
            'motivo' => null,
            'bateria_voltaje' => $l->bateria_voltaje !== null ? (float) $l->bateria_voltaje : null,
            'rssi_dbm' => $l->rssi_dbm,
        ]);
        $invalidas = $dispositivo->lecturasInvalidas()->limit(15)->get()->map(static fn ($l): array => [
            'valida' => false,
            'medido_en' => $l->medido_en->toIso8601String(),
            'distancia_cm' => $l->distancia_cm !== null ? (float) $l->distancia_cm : null,
            'porcentaje_llenado' => null,
            'motivo' => $l->motivo,
            'bateria_voltaje' => $l->bateria_voltaje !== null ? (float) $l->bateria_voltaje : null,
            'rssi_dbm' => $l->rssi_dbm,
        ]);

        /** @var array<int, array<string, mixed>> $historial */
        $historial = $validas->concat($invalidas)->sortByDesc('medido_en')->take(15)->values()->all();

        return $historial;
    }
}
