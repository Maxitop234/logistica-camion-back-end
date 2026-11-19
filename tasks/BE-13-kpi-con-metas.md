# BE-13 · KPI con metas (tablero)

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Nelson |
| Depende de | BE-11 (datos de rutas ejecutadas) |
| Origen | Los indicadores iniciales eran métricas, no KPI; ADR-0009 |

## Objetivo
`GET /kpis/dashboard` entrega los seis KPI como resultado contra meta (numerador, denominador, valor, meta y cumple), con series por periodo y las métricas base, calculados solo con datos reales.

## Contexto
- Definiciones y metas iniciales: [ADR-0009](../docs/ADR/0009-kpi-como-resultado-contra-meta.md). Contrato: `docs/API.md` §4.8.
- Hoy el front recibe 404 y muestra un tablero de ejemplo (`kpis_ejemplo.dart`); FE-04 y FE-10 lo reemplazan.

## Alcance
- Incluye: tabla `metas_kpi` con seeder, servicio `CalculadoraDeKpi`, endpoints `dashboard`, `GET /kpis/metas` y `PUT /kpis/metas/{kpi_id}`, filtros por chofer y camión, agrupación por semana, mes, trimestre, semestre y año.
- No incluye: alertas ni exportación (BE-14).

## Pasos sugeridos
1. Migración y seeder de `metas_kpi` con las metas y tolerancias del ADR-0009.
2. `CalculadoraDeKpi`, un método por KPI, en SQL agregado sobre `rutas` y `ruta_puntos`:
   - KPI-02 con `LAG(hora_real_recoleccion) OVER (PARTITION BY ruta_id ORDER BY orden_parada)` y la diferencia de horas estimadas del mismo tramo;
   - series con `date_trunc('week'|'month'|'quarter'|'year', …)` en zona America/Santiago (el semestre se calcula a partir del mes).
3. Denominador 0 → `valor = null`, `cumple = null`. `datos_suficientes = false` si no hay rutas completadas en el periodo.
4. Métricas base: tiempo promedio de ruta, tiempo promedio entre puntos, puntos vaciados, desviación promedio y ranking por chofer y camión (nombres descifrados al serializar).
5. Caché de 60 s por combinación de filtros; se invalida al finalizar una ruta.

## Criterios de aceptación
- [ ] Con un conjunto de rutas de prueba de resultado conocido, cada KPI da exactamente el valor esperado (una prueba por KPI, con el cálculo a mano en un comentario).
- [ ] Sin rutas en el periodo, todos los KPI son `null` y `datos_suficientes = false` (nunca 0 % inventado).
- [ ] Cambiar la meta con `PUT /kpis/metas/KPI-01` cambia `cumple` en el tablero.
- [ ] Responde en < 800 ms con 3 meses de datos de prueba (aprox. 150 rutas y 1.500 paradas).
- [ ] `docs/API.md` §4.8 coincide con la respuesta real.

## Pruebas
`CalculadoraDeKpiTest` (KPI-01 a KPI-06 con bordes de tolerancia) y `KpiDashboardApiTest` (filtros, agrupaciones, roles).

## Archivos probables
`app/Services/Kpi/CalculadoraDeKpi.php`, `app/Models/MetaKpi.php`, `app/Http/Controllers/Api/V1/KpiController.php`, `app/Http/Requests/Kpi/*`, `database/seeders/MetasKpiSeeder.php`, migración.
