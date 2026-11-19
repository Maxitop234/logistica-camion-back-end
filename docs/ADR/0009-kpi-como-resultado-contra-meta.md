# ADR-0009: KPI definidos como resultado contra una meta

- **Estado:** Propuesta
- **Fecha:** 2026-11-18
- **Reemplaza a:** la definición inicial de KPI-01 a KPI-06 (promedios y conteos)
- **Relacionado con:** BE-13, BE-14, FE-10

## Contexto
Los seis indicadores iniciales eran **métricas**, no KPI: "tiempo de ruta" o "tiempo promedio de ruta" describen un valor, pero no dicen si es bueno o malo. Un KPI compara un resultado con un estándar o meta. Varios objetivos estaban además "a definir con la empresa".

## Decisión
Cada KPI será una **proporción de casos que cumplen un estándar, comparada con una meta**, con numerador, denominador, valor, meta y estado (cumple / no cumple). Las seis métricas originales se mantienen como **métricas base** para calcular los KPI y como detalle del tablero.

| ID | KPI | Fórmula | Meta inicial | Revisión |
|---|---|---|---|---|
| KPI-01 | Rutas completadas dentro del tiempo planificado | rutas con duración real ≤ duración estimada × 1,15 / rutas completadas × 100 | ≥ 80 % | Semanal |
| KPI-02 | Tramos sin demora anómala | tramos entre paradas consecutivas con tiempo real ≤ tiempo estimado del tramo + 10 min / tramos medidos × 100 | ≥ 90 % | Semanal |
| KPI-03 | Cumplimiento de paradas planificadas | paradas recolectadas / paradas planificadas en rutas completadas × 100 | ≥ 95 % | Semanal y mensual |
| KPI-04 | Visitas efectivas | paradas recolectadas cuyo nivel observado por el chofer era medio, alto o lleno / paradas recolectadas con nivel informado × 100 | ≥ 85 % | Mensual |
| KPI-05 | Puntualidad en las paradas | paradas con \|hora real − hora estimada\| ≤ 15 min / paradas recolectadas × 100 | ≥ 85 % | Semanal |
| KPI-06 | Visitas evitadas frente a la ruta fija | puntos excluidos por estado "no listo" / puntos que la ruta fija habría visitado (puntos evaluados) × 100 | ≥ 30 % | Semanal y mensual |

**Línea base de KPI-06:** el número de visitas semanales de la ruta fija actual (puntos de cada sector × días de recolección del sector). Los "puntos evaluados" de cada ruta son los puntos de los sectores programados ese día. En el piloto casi todos los puntos se incluyen por calendario (ADR-0013), así que KPI-04 es el que muestra cuántas de esas visitas eran innecesarias.

**Métricas base** (no son KPI): tiempo promedio de ruta, tiempo promedio entre puntos, puntos vaciados, desviación promedio estimado vs real, y ranking de choferes y camiones por puntos atendidos.

**Ejemplo:** "En noviembre se completaron 20 rutas (métrica). 17 terminaron dentro del tiempo planificado con un 15 % de tolerancia: KPI-01 = 85 %, sobre la meta de 80 % → cumple."

Reglas:
- Si el denominador es 0, el KPI se informa como "sin datos" (`null`), nunca como 0 % ni como 100 %.
- Las metas y tolerancias son **propuestas iniciales**: se validan con Somos Terranova y se ajustan tras una línea base de dos semanas. Se guardan en `metas_kpi`, editables por el administrador.
- Los KPI se pueden filtrar por chofer o camión, pero se usan para gestionar la operación, no para sancionar (art. 5° del Código del Trabajo).

## Alternativas consideradas
- **Mantener promedios y conteos:** no permiten saber si la operación cumple o no.
- **KPI de combustible o costo por km:** no hay datos (camiones arrendados, sin OBD2).

## Consecuencias
- La app del chofer debe pedir el **nivel observado** al registrar la recolección (para KPI-04).
- Cambia el contrato de `/kpis/dashboard` y el tablero del front (FE-10).
- La documentación de KPI y sus diagramas deben actualizarse (DOC-02).
