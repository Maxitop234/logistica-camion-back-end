<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Desvio\RegistrarMotivoDesvioRequest;
use App\Models\Chofer;
use App\Models\DesvioRuta;
use App\Models\Ruta;
use App\Services\Desvios\DetectorDeDesvios;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesvioController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    private function formatear(DesvioRuta $d, bool $conChofer = false): array
    {
        return [
            'id' => $d->id,
            'ruta_id' => $d->ruta_id,
            'chofer_id' => $conChofer ? $d->chofer_id : null,
            'inicio_en' => $d->inicio_en->toIso8601String(),
            'fin_en' => $d->fin_en->toIso8601String(),
            'distancia_m' => $d->distancia_m,
            'max_separacion_m' => $d->max_separacion_m,
            'geometria_real' => $d->geometria_real,
            'geometria_planificada' => $d->geometria_planificada,
            'motivo_codigo' => $d->motivo_codigo,
            'motivo_texto' => $d->motivo_texto,
            'revisado_en' => $d->revisado_en?->toIso8601String(),
        ];
    }

    /**
     * Desvíos de una ruta (administrador). En una ruta en curso se detectan al consultar.
     */
    public function porRuta(Ruta $ruta, DetectorDeDesvios $detector): JsonResponse
    {
        if ($ruta->estado === Ruta::ESTADO_EN_CURSO) {
            $detector->detectar($ruta);
        }

        $desvios = DesvioRuta::where('ruta_id', $ruta->id)->orderBy('inicio_en')->get();

        return response()->json([
            'data' => $desvios->map(fn (DesvioRuta $d): array => $this->formatear($d, true))->values(),
        ]);
    }

    /**
     * Desvíos del chofer autenticado; por defecto solo los pendientes de revisión.
     */
    public function indexChofer(Request $request): JsonResponse
    {
        $chofer = $request->user()?->chofer()->first();
        if (! $chofer instanceof Chofer) {
            return response()->json(['data' => []]);
        }

        $consulta = DesvioRuta::where('chofer_id', $chofer->id)->orderByDesc('inicio_en')->limit(30);
        if (! $request->boolean('todos')) {
            $consulta->whereNull('revisado_en');
        }

        return response()->json([
            'data' => $consulta->get()->map(fn (DesvioRuta $d): array => $this->formatear($d))->values(),
        ]);
    }

    /**
     * Registra (opcionalmente) el motivo de un desvío propio o lo marca como omitido.
     */
    public function registrarMotivo(RegistrarMotivoDesvioRequest $request, DesvioRuta $desvio): JsonResponse
    {
        $chofer = $request->user()?->chofer()->first();
        if (! $chofer instanceof Chofer || $desvio->chofer_id !== $chofer->id) {
            return response()->json(['message' => 'No tienes permiso para modificar este desvío.'], 403);
        }

        $desvio->update([
            'motivo_codigo' => $request->input('motivo_codigo'),
            'motivo_texto' => $request->filled('motivo_texto') ? trim((string) $request->input('motivo_texto')) : null,
            'revisado_en' => now(),
        ]);

        return response()->json(['data' => $this->formatear($desvio)]);
    }

    /**
     * Reporte de calles problemáticas: tramos con desvíos repetidos (administrador).
     */
    public function callesProblematicas(Request $request): JsonResponse
    {
        $umbral = max(2, (int) $request->query('umbral', (string) config('ecoruta.desvios.umbral_reporte')));
        $dias = max(1, min(365, (int) $request->query('dias', '90')));

        $desvios = DesvioRuta::query()
            ->where('inicio_en', '>=', now()->subDays($dias))
            ->orderBy('inicio_en')
            ->get()
            ->groupBy('clave_tramo');

        $tramos = [];
        foreach ($desvios as $clave => $grupo) {
            if ($grupo->count() < $umbral) {
                continue;
            }
            $motivos = $grupo->whereNotNull('motivo_codigo')->countBy('motivo_codigo')->all();
            $ultimo = $grupo->last();
            $tramos[] = [
                'clave_tramo' => (string) $clave,
                'cantidad' => $grupo->count(),
                'rutas_distintas' => $grupo->pluck('ruta_id')->unique()->count(),
                'choferes_distintos' => $grupo->pluck('chofer_id')->unique()->count(),
                'primera_vez' => $grupo->first()->inicio_en->toIso8601String(),
                'ultima_vez' => $ultimo->inicio_en->toIso8601String(),
                'sin_motivo' => $grupo->whereNull('motivo_codigo')->count(),
                'motivos' => $motivos,
                'comentarios' => $grupo->pluck('motivo_texto')->filter()->unique()->take(5)->values()->all(),
                'centro' => ['latitud' => $ultimo->centro_latitud, 'longitud' => $ultimo->centro_longitud],
                'geometria_planificada' => $ultimo->geometria_planificada,
                'geometria_real' => $ultimo->geometria_real,
            ];
        }
        usort($tramos, static fn (array $a, array $b): int => $b['cantidad'] <=> $a['cantidad']);

        return response()->json([
            'data' => $tramos,
            'meta' => ['umbral' => $umbral, 'dias' => $dias],
        ]);
    }
}
