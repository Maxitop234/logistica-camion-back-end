# BE-10 · Esquema de rutas y motor OSRM con modo degradado

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Maximiliano |
| Depende de | BE-03 (y BE-12 para la regla de licencia; si aún no existe, dejar el punto de integración) |
| Origen | SLA de generación ≤ 2 min; ADR-0005, ADR-0013 |

## Objetivo
El administrador genera la ruta diaria de un camión con los puntos reales que están `alto` o `lleno`, en orden óptimo sobre calles reales (OpenStreetMap), con horas estimadas y la geometría para dibujarla.

## Contexto
- El front ya llama a `POST /rutas/generar` y `GET /rutas`. Hoy reciben 404 y el front muestra rutas de ejemplo (FE-04 elimina eso). El contrato está en `docs/API.md` §4.7.
- La planta (origen y destino) está en `PLANTA_LATITUD` y `PLANTA_LONGITUD`.
- **Volumen esperado:** varios sectores por día, con **más de 100 puntos diarios** repartidos entre **dos camiones**.
- OSRM limita por defecto `/trip` a **100 puntos** (`--max-trip-size`) y `/table` a 100 (`--max-table-size`). Con la carga real hay que subir esos límites o dividir la ruta.

## Alcance
- Incluye: tablas `rutas` y `ruta_puntos`, servicio OSRM en `compose.yaml` con script de preparación de datos, interfaz `OptimizadorDeRutas` con `OsrmOptimizador` y `ProximidadOptimizador`, `GeneradorDeRutas`, endpoints `generar`, `index`, `show` y `cancelar`.
- No incluye: la ejecución por el chofer (BE-11).

## Pasos sugeridos
1. Migraciones `create_rutas_table` y `create_ruta_puntos_table` (`docs/DATA_MODEL.md`), modelos `Ruta` y `RutaPunto`, enums `EstadoRuta` y `EstadoParada`.
2. **OSRM local** (iniciar `osrm-routed` con `--max-trip-size 400 --max-table-size 400`), con `docker/osrm/preparar.sh`:
   - descarga `chile-latest.osm.pbf` de Geofabrik;
   - recorta con `osmium extract --bbox=-72.75,-38.82,-72.50,-38.65` (ajustar a Temuco–Padre Las Casas);
   - ejecuta `osrm-extract -p /opt/car.lua`, `osrm-partition` y `osrm-customize`.
   Servicio `osrm` (`osrm/osrm-backend`, `osrm-routed --algorithm mld`) en `compose.yaml`, con los datos en `docker/osrm/data/` (ignorado por Git).
3. `OsrmOptimizador`: `GET {OSRM_BASE_URL}/trip/v1/driving/{planta;p1;…;pn}?source=first&roundtrip=true&geometries=polyline&overview=full`, con timeout `RUTEO_TIMEOUT_SEGUNDOS`. Obtener el orden de `waypoints[].waypoint_index`, la duración y distancia de `trips[0].legs` y la polilínea de `trips[0].geometry`.
4. `ProximidadOptimizador`: vecino más cercano desde la planta (PostGIS `ST_Distance` o Haversine), con duración estimada a una velocidad media configurable y polilínea de líneas rectas.
5. `GeneradorDeRutas::generar(Camion, Chofer, fecha, ?Sector)`:
   - valida la licencia del chofer (BE-12) y que no exista otra ruta no cancelada del camión en esa fecha;
   - recibe `sector_ids` (por defecto, los sectores del día que no están asignados a otra ruta de la misma fecha, para repartir entre los dos camiones);
   - aplica el criterio del [ADR-0013](../docs/ADR/0013-estado-de-puntos-sin-sensor.md): incluye estado vigente `alto`/`lleno` y estado desconocido (calendario), excluye estado vigente `vacio`/`bajo`/`medio`, y respeta `incluir_punto_ids` y `excluir_punto_ids`; guarda `motivo_inclusion` por parada;
   - con 0 puntos a visitar responde 422 "No hay puntos para recolectar en los sectores elegidos";
   - intenta OSRM y, ante cualquier error, usa proximidad y marca `modo_calculo`;
   - calcula `hora_estimada_llegada` acumulando duraciones desde la hora de salida (configurable, p. ej. 08:30 hora de Chile, guardada en UTC);
   - persiste todo en una transacción y audita `ruta_generada`.
6. Endpoints `POST /rutas/generar`, `GET /rutas` (filtros), `GET /rutas/{id}`, `POST /rutas/{id}/cancelar`.

## Criterios de aceptación
- [ ] Con 20 puntos (8 listos con sensor, 5 desconocidos y 7 con estado vigente bajo), la ruta tiene 13 paradas, `puntos_excluidos_count = 7` y `total_puntos_evaluados = 20`, con el `motivo_inclusion` correcto.
- [ ] Generar un día real con varios sectores y más de 100 puntos en dos rutas, una por camión, sin repetir sectores.
- [ ] Con OSRM caído (`Http::fake` con error o timeout), la ruta se genera igual con `modo_calculo = "proximidad"`.
- [ ] La respuesta coincide con lo que parsea `RutaActiva.fromJson` en el front (revisar `lib/features/rutas/domain/ruta_activa.dart`).
- [ ] Generar con 270 puntos tarda < 2 min (en la práctica, segundos con OSRM local).
- [ ] Prueba manual: una ruta real sobre los puntos importados se dibuja por calles reales en el front (FE-11).

## Pruebas
`GenerarRutaTest` (OSRM simulado con `Http::fake` y una respuesta grabada de OSRM real en `tests/Fixtures/osrm_trip.json`), `ProximidadOptimizadorTest` y `RutasApiTest` (listado, filtros, cancelar, roles).

## Archivos probables
`app/Services/Rutas/{OptimizadorDeRutas,OsrmOptimizador,ProximidadOptimizador,GeneradorDeRutas}.php`, `config/ruteo.php`, `docker/osrm/preparar.sh`, `compose.yaml`, migraciones, modelos, `app/Http/Controllers/Api/V1/RutaController.php`, `app/Http/Resources/{Ruta,RutaPunto}Resource.php`.
