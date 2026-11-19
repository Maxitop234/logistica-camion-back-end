# ADR-0003: PostgreSQL con PostGIS como base de datos

- **Estado:** Aceptada (PostGIS habilitado en BE-01, 2026-11-18)
- **Fecha:** 2026-10-24
- **Relacionado con:** BE-01, BE-03, BE-10

## Contexto
El dominio es relacional (chofer → camión → ruta → paradas → puntos) y geoespacial: puntos georreferenciados, distancias y orden por proximidad cuando falla el motor de ruteo.

## Decisión
Usaremos **PostgreSQL** (RDS en producción, `pgsql` de Sail en desarrollo) con la extensión **PostGIS** para:
- la columna `puntos.ubicacion geography(Point, 4326)` con índice GiST;
- el modo degradado de ruteo (vecino más cercano con `ST_Distance`);
- las validaciones "dentro de la zona de Temuco–Padre Las Casas".

Las coordenadas se exponen en la API como `latitud` y `longitud` decimales. Las columnas `decimal(10,7)` existentes en `camiones` se mantienen para la última posición, porque basta para el mapa.

## Alternativas consideradas
- **MariaDB:** muy cercana en la evaluación, pero con soporte espacial más limitado.
- **Solo columnas decimales y cálculo con Haversine en PHP:** funciona para el piloto, pero contradice el diseño geoespacial y obliga a cargar todos los puntos en memoria.
- **MongoDB:** descartado por la naturaleza relacional del dominio.

## Consecuencias
- `compose.yaml` debe usar una imagen con PostGIS (p. ej. `postgis/postgis`) y CI necesita un servicio PostgreSQL; las pruebas dejan de correr en SQLite.
- Las migraciones que usan PostGIS ejecutan `CREATE EXTENSION IF NOT EXISTS postgis`.
