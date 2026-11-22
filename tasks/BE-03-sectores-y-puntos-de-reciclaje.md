# BE-03 · Sectores y puntos de reciclaje (modelo + API)

| Campo | Valor |
|---|---|
| Estado | Hecha |
| Prioridad | P0 |
| Responsable sugerido | Nelson |
| Depende de | BE-01 |
| Origen | `docs/DATA_MODEL.md` (sectores, puntos); ADR-0013 |

## Objetivo
Administrar los puntos de reciclaje reales con sector, día de recolección, ubicación y estado de llenado, y exponerlos por API para el mapa, el generador de rutas y los sensores.

## Contexto
- Las migraciones `2026_10_28_165602_puntos.php` y `2026_10_28_165616_dispositivo.php` existen con `up()` vacío.
- Somos Terranova organiza sus puntos de reciclaje en **sectores**; cada sector tiene **1 a 3 días fijos de recolección** por semana.
- Solo habrá 2 sensores: la mayoría de los puntos tendrá **estado desconocido**, y eso debe modelarse explícitamente ([ADR-0013](../docs/ADR/0013-estado-de-puntos-sin-sensor.md)).
- El front espera `latitud`, `longitud`, `estado_llenado` y `porcentaje_llenado` (`lib/features/rutas/domain`).

## Alcance
- Incluye: tablas `sectores` y `puntos`, enums `EstadoLlenado`, `TipoPunto` y `OrigenEstado`, modelos, factories, FormRequests, Resources, controladores y rutas `/sectores` y `/puntos` (rol administrador).
- No incluye: importar los datos reales (BE-04) ni la relación con dispositivos (BE-05).

## Pasos sugeridos
1. Eliminar las dos migraciones vacías y crear `create_sectores_table` y `create_puntos_table` según `docs/DATA_MODEL.md`. Agregar `ubicacion` como `geography(point, 4326)` (`$table->geography('ubicacion', subtype: 'point', srid: 4326)`) con índice espacial.
2. `app/Enums/EstadoLlenado.php` con `desdePorcentaje(int $porcentaje): self` (umbrales 10/40/70/89) y `estaListo(): bool` (alto o lleno). Es la **única** implementación de los umbrales; BE-06 y BE-10 la reutilizan.
3. Modelo `Punto` que mantiene `ubicacion` sincronizada con latitud y longitud (evento `saving`), con scopes `activos()`, `listos()` y `delSector()`.
4. CRUD con filtros `estado_llenado` (lista separada por comas), `sector_id`, `activo` y `con_dispositivo`. Validar las coordenadas dentro de la caja de Temuco–Padre Las Casas (configurable en `config/ecoruta.php`).
5. Actualización manual del estado: `origen_estado = manual` y `ultima_actualizacion_estado = now()`. Si llega solo el porcentaje, el estado se deriva con el enum.
5b. Servicio `VigenciaDeEstado` que calcula `estado_vigente_hasta` según el origen y el calendario del sector (tabla del ADR-0013), más el atributo `estado_vigente` en el recurso. BE-06 y BE-11 lo reutilizan al cambiar el estado.
6. `DELETE` es una baja lógica; `409` si el punto está en una ruta `en_curso` (dejar el chequeo listo para cuando exista BE-10).
7. Actualizar `docs/API.md` §4.2 y `docs/DATA_MODEL.md`.

## Criterios de aceptación
- [x] `GET /api/v1/puntos?estado_llenado=alto,lleno` devuelve solo puntos listos y activos.
- [x] Un chofer recibe 403 en todos los endpoints de puntos y sectores.
- [x] Coordenadas fuera de la zona → 422 con un mensaje claro.
- [x] `EstadoLlenado::desdePorcentaje` cubre los bordes 0, 10, 11, 40, 41, 70, 71, 89, 90 y 100.
- [x] Un punto recolectado el martes en un sector de martes y jueves queda con estado vigente hasta el miércoles; el jueves cuenta como desconocido.
- [x] Un punto nunca medido tiene `estado_llenado = null` y `estado_vigente = false` (no `vacio`).
- [x] Las migraciones vacías ya no existen y `migrate:fresh --seed` funciona.

## Pruebas
`SectorApiTest`, `PuntoApiTest` (CRUD, filtros, roles, validación) y `EstadoLlenadoTest` (unitaria).

## Archivos probables
`database/migrations/*_create_{sectores,puntos}_table.php`, `app/Enums/*`, `app/Models/{Sector,Punto}.php`, `app/Http/Controllers/Api/V1/{Sector,Punto}Controller.php`, `app/Http/Requests/{Sector,Punto}/*`, `app/Http/Resources/{Sector,Punto}Resource.php`, `database/factories/*`, `config/ecoruta.php`, `routes/api.php`.

## Resultado (2026-11-21)

**Pruebas:** `SectorApiTest`, `PuntoApiTest`, `VigenciaDeEstadoTest` y `tests/Unit/EstadoLlenadoTest`, además de ajustes en `KpiBusinessApiTest`, `DemoEnvironmentTest`, `DatabaseSeederTest` y `RutaApiTest`. `migrate:fresh --seed` se verificó a mano en modo empresa (12 sectores) y en modo demo (12 sectores y 16 puntos, todos con sector).

**Decisiones y diferencias con los pasos sugeridos:**
- **Sectores reales.** `SectorSeeder` carga los 12 sectores de la planilla de georreferenciación (días marcados y puntos de mayo); lo llaman `DatabaseSeeder` y `DemoSeeder`. En S.Martin y Amanecer la frecuencia declarada no coincide con los días marcados: se dejaron tal cual, mandan los días. **Confirmar con la empresa.**
- **Se reemplazó la columna de texto `puntos.sector`** (lista cerrada de 5 sectores inventados con clasificación por cuadrante) por `sector_id`. `sectorResuelto()` devuelve ahora el nombre del sector real o `"Sin sector"`; KPI, demo e historial de demo agrupan por los sectores reales.
- **El código manda sobre la documentación:** se mantiene `origen_actualizacion` (`manual`, `chofer` = observación, `sensor_iot`, más el nuevo `recoleccion`) en lugar de `origen_estado`, y `tipo_punto` sigue siendo `condominio`/`calle`.
- **`ubicacion` es una columna generada** de PostgreSQL (siempre sincronizada, también con importaciones masivas) en vez de un evento `saving`.
- **"Siguiente día de recolección"** es el primero estrictamente posterior a la fecha local del evento. Sin sector o sin días, un estado manual, observado o de recolección vale hasta el fin del día local.
- **Punto sin sector:** al crearlo o al aprobar la propuesta de un chofer sin `sector_id`, se asigna el sector del punto activo más cercano y queda con `requiere_revision = true` (bandeja "Por revisar" de FE-05).
- **Fuera del alcance pero necesario por los nulos:** al sincronizar recolecciones (`RutaController::sincronizarLote`) el punto se actualiza vía el modelo con origen `recoleccion` (recalcula la vigencia), y `porcentaje_llenado_al_vaciar` queda `null` si el punto nunca fue medido.

**Avisos para otras tareas:**
- **FE-05:** el formulario de puntos del front envía `sector` con los 5 nombres inventados; hoy solo `Poniente` coincide con un sector real y los demás responden 422. Debe usar `GET /sectores` y `sector_id`, y mostrar "sin medición" cuando `estado_llenado` sea `null` (hoy el front lo trata como 0 %/vacío).
- **BE-10:** `RutaController::generar` todavía filtra por el estado guardado (`alto`/`lleno`) sin considerar `estado_vigente` ni el calendario del sector; debe aplicar ADR-0013 con `Punto::estadoVigente()` y `Sector::esDiaDeRecoleccion()`.
- **Ambientes ya desplegados:** después de `migrate`, ejecutar `db:seed --class=SectorSeeder` (y `DemoSeeder` en demo) para cargar los sectores y reasignar los puntos de demostración.
- `composer types:check` mantiene 14 errores previos en `GpsController`, `KpiAlertasService`, `KpiCalculatorService` y `KpiExportService` (no son de esta tarea).
