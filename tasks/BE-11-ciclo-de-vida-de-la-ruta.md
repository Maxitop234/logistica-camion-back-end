# BE-11 · Ciclo de vida de la ruta del chofer (offline idempotente)

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Maximiliano |
| Depende de | BE-10 |
| Origen | ADR-0008, ADR-0013 |

## Objetivo
El chofer obtiene su ruta del día, la inicia, registra cada recolección (con o sin señal, sin duplicados) y la finaliza. Esto deja los datos reales con los que se calculan los KPI.

## Contexto
El front ya implementa estas llamadas (`lib/features/driver/data/ruta_chofer_repository.dart`): `GET /chofer/ruta-activa`, `POST /rutas/{id}/iniciar`, `POST /rutas/{id}/sincronizar-lote` y `POST /rutas/{id}/finalizar`. Contrato en `docs/API.md` §4.7.

## Alcance
- Incluye: los cinco endpoints del chofer, `RutaPolicy`, efectos sobre el camión y los puntos, `nivel_observado`, y `ruta_activa` en `/auth/me`.

## Pasos sugeridos
1. `RutaPolicy`: el chofer solo ve y opera rutas donde `chofer_id` es su perfil.
2. `GET /chofer/ruta-activa`: ruta `en_curso` o, si no hay, la `planificada` de hoy (zona horaria America/Santiago). `{data: null}` si no hay ninguna.
3. `iniciar`: `planificada → en_curso`, `hora_inicio_real`, camión `en_ruta`. 409 si ya está en curso o completada.
4. `recolectar-punto` y `sincronizar-lote`, ambos con un servicio `RegistradorDeRecolecciones`:
   - si el `sync_uuid` existe, se cuenta como duplicada;
   - si no, la parada pasa a `recolectado` con `hora_real_recoleccion`, `nivel_observado` y `observaciones`;
   - `nivel_observado` es obligatorio (422 si falta en `recolectar-punto`; en lotes antiguos se acepta nulo y cuenta como "sin dato");
   - el punto queda en `vacio`, 0 %, `origen_estado = recoleccion`, con `estado_vigente_hasta` calculado por `VigenciaDeEstado` (ADR-0013), solo si la hora es posterior a su último estado;
   - se aceptan registros de una ruta ya `completada` (sincronización tardía) mientras la parada siga pendiente u omitida;
   - el índice único de la base de datos resuelve la carrera entre dos peticiones simultáneas (capturar `UniqueConstraintViolationException`).
5. `finalizar`: `en_curso → completada`, `hora_termino_real`, paradas pendientes → `omitido`, camión `disponible`.
6. `UserResource`: llenar `ruta_activa` (`{id, estado, paradas_completadas, paradas_totales}`).

## Criterios de aceptación
- [ ] Enviar dos veces el mismo lote → la segunda respuesta indica `aplicadas: 0, duplicadas: n` y la base de datos no cambia.
- [ ] Un chofer recibe 403 al operar la ruta de otro chofer.
- [ ] Después de recolectar, el punto queda `vacio` con 0 % y el mapa del panel lo refleja.
- [ ] Al finalizar con paradas pendientes, quedan `omitido` y cuentan en KPI-03.
- [ ] La app real (FE) completa el flujo contra staging, incluido el modo avión a mitad de la ruta (registrar el resultado aquí).

## Pruebas
`RutaChoferTest` (flujo completo, estados inválidos, permisos) y `SincronizacionIdempotenteTest`.

## Archivos probables
`app/Http/Controllers/Api/V1/RutaChoferController.php`, `app/Policies/RutaPolicy.php`, `app/Services/Rutas/RegistradorDeRecolecciones.php`, `app/Http/Requests/Ruta/*`, `app/Http/Resources/UserResource.php`.
