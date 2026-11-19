# ADR-0013: Estado de los puntos sin sensor y criterio para incluirlos en la ruta

- **Estado:** Propuesta
- **Fecha:** 2026-11-18
- **Relacionado con:** BE-03, BE-10, BE-11, FE-11; ADR-0009 (KPI-04 y KPI-06)

## Contexto
- Somos Terranova atiende **cientos de puntos** organizados en sectores. Cada sector tiene días fijos de recolección (1 a 3 por semana), lo que da **del orden de mil visitas por semana** con la ruta fija.
- El piloto IoT tiene **dos sensores**: más del 99 % de los puntos no tendrá medición automática.
- La regla inicial ("solo entran los puntos `alto` o `lleno`") no se puede aplicar a un punto cuyo estado nadie conoce. Además, una implementación ingenua lo rompería: al recolectar, el punto queda `vacio` y, si nadie lo vuelve a medir, **quedaría excluido para siempre**.

## Decisión
1. **Estado vigente.** Cada punto tiene un estado vigente o desconocido, según su origen:

   | Origen del estado | Vigente hasta |
   |---|---|
   | `sensor` (lectura IoT) | `ESTADO_SENSOR_VIGENCIA_HORAS` después de la lectura (24 h por defecto) |
   | `manual` (administrador) | El siguiente día de recolección de su sector, inclusive |
   | `observacion` (nivel que ve el chofer al llegar) | El siguiente día de recolección de su sector, inclusive |
   | `recoleccion` (vaciado) | La víspera del siguiente día de recolección: ese día vuelve a ser desconocido, porque se acumuló material sin medir |

   Se guarda como `puntos.estado_vigente_hasta`, que se recalcula en cada cambio de estado.
2. **Inclusión al generar la ruta** de los sectores programados ese día:
   - estado vigente `alto` o `lleno` → **se incluye**;
   - estado vigente `vacio`, `bajo` o `medio` → **se excluye** y cuenta en KPI-06;
   - estado desconocido → **se incluye por calendario** (el comportamiento actual de la empresa).

   El administrador puede incluir o excluir puntos a mano antes de confirmar la ruta (FE-11). La decisión queda guardada en `ruta_puntos`.
3. **Evidencia en terreno:** el chofer registra el `nivel_observado` de **cada** parada visitada (obligatorio en la app). Así se mide cuántas visitas por calendario encontraron el contenedor con poco material (KPI-04). Ese dato justifica ampliar los sensores.
4. **Fuera de alcance por ahora:** estimar la tasa de llenado de los puntos sin sensor a partir de su historial. Es una mejora natural, pero la predicción está fuera del alcance actual; queda en la hoja de ruta posterior.

## Alternativas consideradas
- **Tratar el estado desconocido como `vacio` (excluir):** dejaría de visitar 476 puntos.
- **Ignorar el estado y usar siempre el calendario:** no aprovecha los sensores ni la observación del chofer; no hay ruta dinámica que mostrar.
- **Ajustar a mano el estado de todos los puntos cada día:** no escala a cientos de puntos.

## Consecuencias
- La demo es honesta: la ruta excluye puntos **solo cuando hay datos** (sensor, ajuste manual u observación vigente) y lo muestra en pantalla.
- En el piloto, KPI-06 (visitas evitadas) será bajo porque casi todo se visita por calendario. KPI-04 (visitas efectivas) cuantifica el problema que resolverían más sensores. Esto se explica así en la demo.
- `puntos` agrega `estado_vigente_hasta` y el origen `observacion`. El generador necesita el calendario del sector (`sectores.dias_recoleccion`).
