<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kpi\UpdateParametrosRequest;
use App\Models\AuditLog;
use App\Models\ParametroOperativo;
use App\Services\Iot\SimuladorDeSensores;
use App\Services\KpiAlertasService;
use App\Services\KpiCalculatorService;
use App\Services\KpiExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class KpiController extends Controller
{
    public function __construct(
        private readonly KpiCalculatorService $calculator,
        private readonly KpiAlertasService $alertasService,
        private readonly KpiExportService $exportService,
    ) {}

    /**
     * Tablero completo con los 11 KPI de negocio y parámetros operativos.
     */
    public function dashboard(Request $request): JsonResponse
    {
        SimuladorDeSensores::completarSiDemo();
        [$inicio, $fin, $agrupacion] = $this->resolverFiltro($request);

        return response()->json([
            'data' => $this->calculator->calcularDashboard($inicio, $fin, $agrupacion),
        ]);
    }

    /**
     * Alertas y recomendaciones derivadas de los indicadores del período.
     */
    public function alertas(Request $request): JsonResponse
    {
        SimuladorDeSensores::completarSiDemo();
        [$inicio, $fin] = $this->resolverFiltro($request);

        return response()->json([
            'data' => $this->alertasService->calcular($inicio, $fin),
        ]);
    }

    /**
     * Lista los parámetros operativos (indicando cuáles siguen como referencia).
     */
    public function parametros(): JsonResponse
    {
        $catalogo = array_values(ParametroOperativo::catalogoResuelto());

        return response()->json([
            'data' => $catalogo,
            'meta' => [
                'tiene_valores_referencia' => collect($catalogo)->contains(
                    static fn (array $p): bool => (bool) $p['es_referencia']
                ),
            ],
        ]);
    }

    /**
     * Actualiza uno o varios parámetros generales, marcándolos como propios de la empresa.
     */
    public function actualizarParametros(UpdateParametrosRequest $request): JsonResponse
    {
        $datos = $request->validated();

        DB::transaction(function () use ($datos, $request): void {
            foreach ($datos as $clave => $valor) {
                $def = ParametroOperativo::DEFINICIONES[$clave] ?? null;
                if ($def === null) {
                    continue;
                }

                ParametroOperativo::updateOrCreate(
                    ['clave' => $clave],
                    [
                        'valor' => (float) $valor,
                        'unidad' => $def['unidad'],
                        'es_referencia' => false,
                        'actualizado_por_user_id' => $request->user()?->id,
                    ],
                );
            }

            AuditLog::registrar('kpi_parametros_actualizados', $request->user(), $request, [
                'claves' => array_keys($datos),
            ]);
        });

        return $this->parametros();
    }

    /**
     * Exporta el tablero de 11 KPI en CSV (UTF-8 BOM) o PDF (`barryvdh/laravel-dompdf`).
     */
    public function exportar(Request $request): Response|JsonResponse
    {
        $formato = strtolower((string) $request->query('formato', 'pdf'));
        if (! in_array($formato, ['csv', 'pdf'], true)) {
            return response()->json(['message' => 'Formato no soportado. Usa pdf o csv.'], 422);
        }

        [$inicio, $fin, $agrupacion] = $this->resolverFiltro($request);
        $dashboard = $this->calculator->calcularDashboard($inicio, $fin, $agrupacion);
        $nombreBase = sprintf('kpis_%s_%s', $inicio->format('Y-m-d'), $fin->format('Y-m-d'));

        if ($formato === 'csv') {
            $csv = $this->exportService->generarCsv($dashboard);

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$nombreBase}.csv\"",
            ]);
        }

        $pdfBytes = $this->exportService->generarPdf($dashboard);

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$nombreBase}.pdf\"",
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolverFiltro(Request $request): array
    {
        $request->validate([
            'fecha_inicio' => ['sometimes', 'date'],
            'fecha_fin' => ['sometimes', 'date', 'after_or_equal:fecha_inicio'],
            'agrupacion' => ['sometimes', 'in:dia,semana,mes'],
        ]);

        $fin = $request->filled('fecha_fin')
            ? Carbon::parse((string) $request->query('fecha_fin'))
            : Carbon::today();
        $inicio = $request->filled('fecha_inicio')
            ? Carbon::parse((string) $request->query('fecha_inicio'))
            : $fin->copy()->subDays(29);
        $agrupacion = (string) $request->query('agrupacion', 'semana');

        return [$inicio, $fin, $agrupacion];
    }
}
