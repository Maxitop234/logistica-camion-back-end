# Decisiones de arquitectura (ADR)

Cada decisión importante se registra en un archivo corto e inmutable, el *Architecture Decision Record*. Así, tanto el equipo como los agentes de IA saben **qué se decidió, por qué y qué alternativas se descartaron**, y nadie revierte por accidente una decisión tomada.

## Índice

| ADR | Título | Estado | Fecha |
|---|---|---|---|
| [0001](0001-registrar-decisiones-con-adr.md) | Registrar las decisiones de arquitectura con ADR | Aceptada | 2026-11-18 |
| [0002](0002-api-rest-laravel-con-sanctum-y-roles.md) | API REST en Laravel, versionada, con Sanctum y roles | Aceptada | 2026-10-23 |
| [0003](0003-postgresql-con-postgis.md) | PostgreSQL con PostGIS como base de datos | Aceptada | 2026-10-24 |
| [0004](0004-gps-desde-el-telefono-del-chofer.md) | Rastreo GPS desde el teléfono del chofer | Aceptada | 2026-10-24 |
| [0005](0005-openstreetmap-y-osrm-en-lugar-de-google-maps.md) | OpenStreetMap y OSRM en lugar de Google Maps Platform | **Propuesta** | 2026-11-18 |
| [0006](0006-cifrado-de-datos-personales-del-chofer.md) | Cifrado de los datos personales del chofer | Aceptada | 2026-11-14 |
| [0007](0007-conectividad-del-nodo-iot.md) | Conectividad del nodo IoT (Wi-Fi, celular o LoRaWAN) | **Propuesta** | 2026-11-18 |
| [0008](0008-sincronizacion-offline-idempotente.md) | Sincronización offline idempotente con `sync_uuid` | Aceptada | 2026-10-24 |
| [0009](0009-kpi-como-resultado-contra-meta.md) | KPI definidos como resultado contra una meta | **Propuesta** | 2026-11-18 |
| [0010](0010-validacion-documental-de-licencias.md) | Validación documental de las licencias de conducir | **Propuesta** | 2026-11-18 |
| [0011](0011-tiempo-real-por-polling-http.md) | Tiempo real mediante polling HTTP | Aceptada | 2026-10-28 |
| [0012](0012-token-por-dispositivo-iot.md) | Autenticación de cada sensor con su propio token | Aceptada | 2026-10-28 |
| [0013](0013-estado-de-puntos-sin-sensor.md) | Estado de los puntos sin sensor y criterio para incluirlos en la ruta | **Propuesta** | 2026-11-18 |

El cliente Flutter tiene sus propios ADR en `../logistica_camion_front_end/docs/ADR/`.

## Estados

- **Propuesta:** redactada, falta que el equipo la confirme. Los agentes no la tratan como obligatoria, pero las tareas que la citan se escriben suponiendo que se acepta.
- **Aceptada:** vigente y obligatoria.
- **Reemplazada por NNNN:** ya no rige; se mantiene como historia.
- **Rechazada:** se evaluó y se descartó.

Para aceptar una propuesta, cambia su estado y la fecha, y actualiza esta tabla. **No edites el contenido de un ADR aceptado:** si la decisión cambia, crea uno nuevo que lo reemplace.

## Cómo crear un ADR

1. Copia la plantilla de abajo en `NNNN-titulo-en-minusculas.md`, con el número siguiente.
2. Complétala en menos de una página.
3. Agrégala a la tabla y enlázala desde la tarea o el documento que la motivó.

```markdown
# ADR-NNNN: Título en forma de decisión

- **Estado:** Propuesta | Aceptada | Reemplazada por NNNN | Rechazada
- **Fecha:** AAAA-MM-DD
- **Decisores:** Maximiliano González, Nelson Ponce
- **Relacionado con:** tareas, ADR, documentos de `docs/`

## Contexto
Qué problema o fuerza obliga a decidir. Hechos, no opiniones.

## Decisión
Qué se hará, en voz activa ("Usaremos…").

## Alternativas consideradas
Opciones descartadas y por qué.

## Consecuencias
Qué se gana, qué se pierde y qué trabajo genera.
```
