# Trabajo futuro: ruteo propio con calles penalizadas

**Estado:** propuesta, no implementada. Ninguna parte del sistema actual depende de esto.

## Problema

El servidor público de OSRM (`router.project-osrm.org`) calcula siempre con el mapa de OpenStreetMap tal cual: no permite pedirle que evite o penalice una calle concreta. Cuando un camión no puede (o no debe) pasar por una calle —sentido contrario mal mapeado, obras, ancho insuficiente— la ruta recalculada vuelve a proponerla.

## Medias vueltas y calles solo-bus (verificado el 2026-11-25 contra el servidor público)

Un camión no puede dar media vuelta en la calle donde está el contenedor: debe seguir y rodear la manzana. Lo que hoy se hace y lo que respeta de verdad `router.project-osrm.org`:

- **`/route` con `continue_straight=true`** (generar e iniciar en el backend; navegación y recálculo en la app). En `/route` el perfil `car` del servidor público ya lo trae por defecto (mismo resultado con y sin el parámetro; con `false` sí acepta medias vueltas); se envía explícito para no depender del valor por defecto ni del servidor.
- **`/trip` no acepta `continue_straight`** (responde `InvalidQuery`) y su trazado da media vuelta en las paradas. Por eso al generar, `/trip` solo decide el orden y el trazado, distancias y horas salen de un `/route` posterior; si ese `/route` falla se conserva el del `/trip`.
- **Navegación (origen → una parada):** con solo dos coordenadas `continue_straight` no actúa (no hay parada intermedia). Lo que evita la media vuelta al salir de una parada es `bearings` en el origen: la app envía el último rumbo conocido del camión con ±45°; si OSRM responde `NoSegment` (no hay calle con ese sentido cerca), vuelve a pedir sin rumbo.
- **`approaches=curb`:** el servidor lo acepta, pero no elimina ninguna media vuelta más y alarga las rutas de la demo (8 paradas: +626 m; 16 paradas: +1,9 km). Queda desactivado.
- **`/table`** (orden por cercanía al iniciar) no tiene esos parámetros: la matriz supone que se puede salir de una parada en cualquier sentido, así que el orden puede no ser el óptimo sin medias vueltas (el trazado sí las evita).
- **Lo que no se puede evitar con el servidor público:**
  - Medias vueltas en un **cruce** a mitad de tramo: el perfil `car` las permite con una penalización de tiempo; `continue_straight` solo actúa en las paradas. En la ruta demo de 16 paradas quedan 3 maniobras "uturn" de OSRM a mitad de tramo (Huichahue junto a la planta, Panamericana Sur y una calle sin nombre junto a Los Pablos); no se revisó en terreno si son retornos permitidos.
  - **Calles sin salida:** si el contenedor está en un pasaje ciego (p. ej. el punto de Pedro de Valdivia queda en La Península), la media vuelta es inevitable.
  - **Calles solo-bus:** en OpenStreetMap, dentro de Temuco y Padre Las Casas solo hay 3 vías etiquetadas para transporte público (`psv=designated` o `bus=yes`), todas con `access=no`, que el perfil `car` ya excluye (verificado: ninguna ruta de prueba las usa). No hay pistas solo-bus mapeadas (`bus:lanes`, `lanes:psv`, `busway`), y el servidor público no ofrece `exclude` útil (`exclude=motorway` responde "Exclude flag combination is not supported") ni evita calles arbitrarias. Cualquier vía exclusiva de buses que no esté etiquetada en OSM puede entrar en una ruta.

La solución completa es un **servidor OSRM propio con perfil de camión**: `u_turn_penalty` muy alto (o prohibir medias vueltas fuera de calles sin salida), `continue_straight_at_waypoint = true`, restricciones `hgv`/`maxweight`/`maxheight`, excluir `bus=designated`/`psv=designated` y una lista local de calles solo-bus de Temuco (las que OSM no tenga etiquetadas, idealmente corregidas también en OSM). Encaja con el perfil con penalizaciones de la sección siguiente.

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
