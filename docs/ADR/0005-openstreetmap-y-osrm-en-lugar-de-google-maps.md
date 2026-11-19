# ADR-0005: OpenStreetMap y OSRM en lugar de Google Maps Platform

- **Estado:** Propuesta
- **Fecha:** 2026-11-18
- **Reemplaza a:** la elección inicial de Google Maps Platform
- **Relacionado con:** BE-10, FE-01, FE-11; ADR-0005 del front-end

## Contexto
- El diseño inicial eligió Google Maps Platform. En la práctica, el mapa del panel quedó **simulado** (un dibujo esquemático) porque no se configuró la API key ni la facturación.
- **OpenStreetMap** es gratuito, tiene datos de Temuco y no tiene cuota.
- OSM se había descartado porque había que instalar y mantener un motor de ruteo propio. Hoy OSRM corre en un contenedor Docker con un extracto recortado de la zona.

## Decisión
1. **Mapa en los clientes:** teselas de OpenStreetMap con `flutter_map`, con la atribución "© OpenStreetMap contributors" visible.
2. **Ruteo:** **OSRM** autoalojado (`osrm/osrm-backend`, perfil `car`) con el extracto de Chile de Geofabrik **recortado a Temuco–Padre Las Casas** (`osmium extract --bbox`). Se usa el servicio `/trip` (orden óptimo con origen y destino en la planta) y `/route` para la geometría (`polyline`).
3. **Modo degradado:** si OSRM falla o tarda más que `RUTEO_TIMEOUT_SEGUNDOS`, se ordena por vecino más cercano con PostGIS y se marca `modo_calculo = "proximidad"`.
4. El motor se encapsula en una interfaz `OptimizadorDeRutas`, con implementaciones `OsrmOptimizador` y `ProximidadOptimizador`. El driver se elige con `RUTEO_DRIVER`.

## Alternativas consideradas
- **Google Maps Platform:** mejor cartografía y tráfico en vivo, pero requiere cuenta de facturación y API key, que nunca se configuraron.
- **API pública de OSRM (`router.project-osrm.org`):** es un servidor de demostración, sin garantías ni uso intensivo permitido. Sirve solo para pruebas puntuales.
- **OpenRouteService o GraphHopper alojados:** tienen planes gratuitos con cuota y requieren API key. Son una alternativa si el servidor no soporta OSRM.

## Consecuencias
- Costo de licencias $0; el mapa y las rutas funcionan con datos reales.
- Sin tráfico en tiempo real: las horas estimadas son de flujo libre. KPI-05 lo absorbe con su tolerancia, y la desviación medida sirve para calibrar.
- Hay que cumplir la política de uso de `tile.openstreetmap.org` (User-Agent identificable, sin descargas masivas). Para producción con más usuarios, evaluar un proveedor de teselas o un servidor propio.
- Los diagramas deben actualizarse (DOC-02).
