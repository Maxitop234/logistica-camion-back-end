<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

class KpiExportService
{
    /**
     * Genera un CSV con separador `;` y BOM UTF-8 (compatible con Excel en
     * español, tildes y `ñ`) con los 11 KPI de negocio y parámetros vigentes.
     *
     * @param  array<string, mixed>  $dashboard
     */
    public function generarCsv(array $dashboard): string
    {
        $periodo = $dashboard['periodo'];
        $filas = [
            ['Reporte Ejecutivo de KPI — EcoRuta Temuco'],
            ['Período', $periodo['fecha_inicio'], $periodo['fecha_fin'], 'Agrupación: '.$periodo['agrupacion']],
            [],
            ['ID', 'Categoría', 'Indicador', 'Valor', 'Meta', 'Semáforo', 'Tendencia (%)', 'Interpretación'],
        ];

        $titulosCat = collect($dashboard['categorias'])->pluck('titulo', 'id');

        foreach ($dashboard['kpis'] as $kpi) {
            $filas[] = [
                $kpi['id'],
                $titulosCat->get($kpi['categoria'], $kpi['categoria']),
                $kpi['titulo'],
                $kpi['sin_datos_suficientes'] ? $kpi['mensaje_sin_datos'] : ($kpi['valor_formateado'] ?? '—'),
                $kpi['meta'],
                $this->etiquetaSemaforo((string) $kpi['semaforo']),
                $kpi['tendencia_pct'] !== null ? number_format((float) $kpi['tendencia_pct'], 1, ',', '.').' %' : '—',
                $kpi['interpretacion'],
            ];
        }

        $filas[] = [];
        $filas[] = ['Parámetros operativos utilizados'];
        $filas[] = ['Parámetro', 'Valor', 'Unidad', 'Origen'];
        foreach ($dashboard['parametros'] as $p) {
            $filas[] = [
                $p['etiqueta'],
                number_format((float) $p['valor'], 2, ',', '.'),
                $p['unidad'],
                $p['es_referencia'] ? 'Valor de referencia' : 'Personalizado por empresa',
            ];
        }

        $escapar = static function (mixed $celda): string {
            $s = (string) $celda;

            return preg_match('/[;"\r\n]/', $s) ? '"'.str_replace('"', '""', $s).'"' : $s;
        };

        $contenido = implode("\r\n", array_map(
            static fn (array $fila): string => implode(';', array_map($escapar, $fila)),
            $filas,
        ));

        return "\xEF\xBB\xBF".$contenido."\r\n";
    }

    /**
     * Genera un reporte PDF UTF-8 usando `barryvdh/laravel-dompdf` (con fuente
     * DejaVu Sans para tildes y `ñ`).
     *
     * @param  array<string, mixed>  $dashboard
     */
    public function generarPdf(array $dashboard): string
    {
        $pdf = Pdf::loadView('kpis.reporte_pdf', [
            'dashboard' => $dashboard,
            'generadoEn' => now()->format('d-m-Y H:i'),
        ])->setPaper('a4', 'portrait');

        return $pdf->output();
    }

    private function etiquetaSemaforo(string $semaforo): string
    {
        return match ($semaforo) {
            'cumple' => 'Cumple',
            'cerca' => 'Cerca de meta',
            'no_cumple' => 'No cumple',
            default => 'Sin datos suficientes',
        };
    }
}
