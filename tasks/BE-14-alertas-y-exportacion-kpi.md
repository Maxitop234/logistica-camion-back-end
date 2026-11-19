# BE-14 · Alertas y exportación de reportes

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Nelson |
| Depende de | BE-13 (y BE-05, BE-08 y BE-12 para sus alertas) |
| Origen | Reportes de gestión y trazabilidad (Ley REP); ADR-0009 |

## Objetivo
El panel muestra alertas accionables generadas por datos reales y permite descargar el reporte del periodo en PDF y CSV.

## Contexto
El front ya llama a `GET /kpis/alertas` y `GET /kpis/exportar?formato=pdf|csv` (`lib/features/kpis/data/`).

## Alcance
- Incluye: tabla `alertas`, generador programado, `GET /kpis/alertas`, exportación CSV (nativa) y PDF.
- Se autoriza agregar **una** dependencia para el PDF (p. ej. `barryvdh/laravel-dompdf`); documentarlo en el PR.

## Pasos sugeridos
1. Migración `create_alertas_table` (`docs/DATA_MODEL.md`).
2. Comando programado `alertas:generar` (cada 15 min) que crea alertas sin duplicar las abiertas del mismo origen:
   - `kpi_bajo_meta` (por semana cerrada);
   - `tramo_anomalo` (tramo con más de 10 min sobre lo estimado);
   - `sensor_sin_reporte` y `bateria_baja` (de BE-05);
   - `gps_sin_senal` (de BE-08);
   - `licencia_por_vencer` (de BE-12).
3. `GET /kpis/alertas?fecha_inicio&fecha_fin&prioridad` ordenado por prioridad y fecha; `PATCH /alertas/{id}` para marcarla resuelta.
4. `GET /kpis/exportar?formato=csv|pdf`: CSV con una fila por KPI y periodo; PDF con resumen ejecutivo (KPI contra meta), métricas base y alertas del periodo. Sin RUT ni teléfonos. `Content-Disposition` con un nombre descriptivo.

## Criterios de aceptación
- [ ] Un sensor sin reportar genera una alerta, y solo una mientras siga abierta.
- [ ] El CSV abre bien en Excel (UTF-8 con BOM, separador `;`) y sus valores coinciden con el tablero.
- [ ] El PDF se genera en < 10 s para un mes y muestra cada KPI con su meta y su estado.
- [ ] Sin datos, el reporte lo dice explícitamente ("sin rutas completadas en el periodo").

## Pruebas
`GenerarAlertasTest` y `ExportarKpiTest` (cabeceras, contenido del CSV, PDF no vacío).

## Archivos probables
`app/Console/Commands/GenerarAlertas.php`, `app/Services/Kpi/ExportadorDeReportes.php`, `resources/views/reportes/kpi.blade.php`, `app/Models/Alerta.php`, migración.
