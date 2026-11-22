# Trabajo futuro: ruteo propio con calles penalizadas

**Estado:** propuesta, no implementada. Ninguna parte del sistema actual depende de esto.

## Problema

El servidor público de OSRM (`router.project-osrm.org`) calcula siempre con el mapa de OpenStreetMap tal cual: no permite pedirle que evite o penalice una calle concreta. Cuando un camión no puede (o no debe) pasar por una calle —sentido contrario mal mapeado, obras, ancho insuficiente— la ruta recalculada vuelve a proponerla.

## Qué datos ya se recogen

- **`telemetria_gps`:** la traza real de cada ruta (`/rutas/{id}/traza`).
- **`desvios_ruta`:** tramos donde el camión se alejó más de 40 m de lo planificado durante al menos 100 m, con la geometría real y la planificada, la fecha, la ruta y el chofer (`docs/API.md` §3.6).
- **Motivo opcional del chofer:** `calle_otro_sentido`, `calle_cerrada_obras`, `no_pasa_camion`, `atajo_mas_rapido` u `otro` (más texto libre).
- **`GET /desvios/calles-problematicas`:** tramos con desvíos repetidos (umbral configurable, por defecto 3) y el recuento de motivos.

## Cómo usarlos más adelante

1. **Servidor de rutas propio** (OSRM o Valhalla autoalojado) con un extracto de OpenStreetMap de La Araucanía; el cliente ya lee `OSRM_BASE_URL`, así que cambiar de servidor no exige tocar la app.
2. **Perfil con penalizaciones.** En OSRM, un perfil Lua propio que lea una tabla de tramos penalizados (por ejemplo, un CSV de `way_id` o segmentos OSM con un factor de velocidad) y los trate como más lentos o intransitables. En Valhalla se logra con *avoid_edges* / `exclude_polygons` por solicitud, sin recompilar el grafo.
3. **Alimentar la tabla desde el reporte.** Un comando programado tomaría los tramos de `calles-problematicas` con motivo `calle_otro_sentido`, `calle_cerrada_obras` o `no_pasa_camion`, los asociaría a vías OSM (*map matching* de `geometria_planificada`) y generaría la tabla de penalizaciones. Los `atajo_mas_rapido` no se penalizan: son candidatos a *bonificar* o a revisar el mapa.
4. **Revisión humana.** Una penalización no debe aplicarse sola: el administrador confirma cada tramo (y puede fijar una fecha de término para obras).
5. **Corregir el mapa.** Si la calle realmente tiene el sentido mal en OSM, lo correcto es corregirlo en OpenStreetMap, no solo penalizarla localmente.

## Limitaciones y riesgos

- La clave de agrupación de desvíos usa celdas de ~100 m; para penalizar una vía concreta hará falta *map matching* sobre la geometría guardada.
- Pocos choferes y pocas rutas por semana: el umbral de 3 desvíos puede tardar en alcanzarse; conviene permitir marcar manualmente un tramo.
- Un servidor propio implica mantenimiento (actualizar el extracto de OSM, memoria del proceso de preprocesado).
