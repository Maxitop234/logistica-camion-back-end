# ADR-0008: Sincronización offline idempotente con `sync_uuid`

- **Estado:** Aceptada
- **Fecha:** 2026-10-24
- **Relacionado con:** SLA de tolerancia a conectividad intermitente; BE-11; ADR-0004 del front-end (Drift)

## Contexto
Los choferes recorren sectores de Temuco con señal intermitente. Ningún registro de recolección puede perderse ni duplicarse: es la base de los KPI y de los reportes de la Ley REP.

## Decisión
- La app escribe cada recolección en un **outbox local** (Drift) con un `sync_uuid` (UUID v4) y la hora real del teléfono, antes de intentar enviarla.
- La API expone `POST /rutas/{ruta}/sincronizar-lote` (y `recolectar-punto` para un solo elemento). Si el `sync_uuid` ya existe, responde 200 sin cambios (`duplicadas`); si no, aplica el cambio.
- `ruta_puntos.sync_uuid` tiene índice único: la base de datos garantiza la idempotencia aunque lleguen dos peticiones en paralelo.
- La hora de la recolección es la del teléfono (`hora_real_recoleccion`), no la de llegada al servidor.

## Alternativas consideradas
- **Reintentar sin identificador:** puede duplicar registros.
- **Clave natural `(ruta_id, punto_id)`:** impide registrar una segunda visita legítima o una corrección.

## Consecuencias
- Los KPI de tiempo usan la hora del teléfono, que puede venir desfasada; la API rechaza horas más de 24 h en el futuro.
- La app debe conservar el outbox hasta recibir un 2xx.
