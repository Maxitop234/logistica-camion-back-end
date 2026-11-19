# Hoja de ruta — EcoRuta Temuco

Plan de trabajo para ambos repositorios, desde el 18-11-2026 hasta el cierre del proyecto (11-12-2026), con lo posterior a la entrega.
El detalle de cada tarea está en [`tasks/README.md`](../tasks/README.md).

## 1. Dónde estamos (18-11-2026)

| Área | Estado |
|---|---|
| GPS y flota | 🟡 Autenticación, roles, camiones y choferes listos. El GPS existe solo como simulación en el front |
| Rutas dinámicas y KPI | 🟡 El front tiene vistas de rutas y KPI con datos de ejemplo; el back-end no tiene endpoints de rutas ni de KPI |
| Piloto de sensores | 🔴 Sin firmware ni endpoints IoT |
| Pruebas y cierre | Por iniciar |

**Prioridades:** eliminar todo dato simulado; mapas con OpenStreetMap; KPI medidos contra metas; secciones de sensores, ancho de banda, administración de GPS y validación de licencias; respaldar con datos y costos las decisiones de hardware. Ver [`ARCHITECTURE.md` §7](ARCHITECTURE.md#7-cambios-respecto-del-diseño-inicial).

## 2. Principio rector hasta la entrega final

> **Todo lo que se muestre en la demo debe venir de la base de datos y de dispositivos reales.**
> Para tener KPI con datos reales hacen falta **rutas reales ejecutadas durante varios días**. Por eso el camino crítico es: servidor accesible → puntos reales → GPS real → rutas reales → KPI.

## 3. Plan por sprints

### Sprint A — "Datos reales" (19-11 al 25-11)

| Back-end | Front-end | IoT / Documentación |
|---|---|---|
| BE-01 PostGIS y CI | FE-12 Configuración por entorno | IOT-01 Firmware del nodo (inicio) |
| BE-02 Endurecimiento de seguridad | FE-01 Mapa OpenStreetMap | DOC-01 Ficha técnica y costos (cotizar y comprar componentes) |
| BE-16 Staging accesible por HTTPS | FE-05 Puntos y sectores | |
| BE-03 Sectores y puntos | FE-09 Licencias en Choferes | |
| BE-04 Importación de sectores y puntos reales | | |
| BE-12 Licencias de conducir | | |
| BE-10 Esquema de rutas y motor OSRM | | |

**Hito A (25-11):** servidor público con los puntos reales de Somos Terranova en un mapa OSM, licencias verificadas y rutas reales generadas con OSRM.

### Sprint B — "Operación real, sensores y secciones nuevas" (26-11 al 02-12)

| Back-end | Front-end | IoT / Documentación |
|---|---|---|
| BE-11 Ciclo de vida de la ruta | FE-11 Generador de rutas real | IOT-01 Firmware (cierre) |
| BE-07 GPS: ping, flota, traza | FE-03 GPS real del teléfono | IOT-02 Instalación y calibración en terreno |
| BE-08 Administración de GPS | FE-02 Flota en vivo real | DOC-01 (cierre con mediciones) |
| BE-05 Kits de sensores | FE-04 Eliminar datos de ejemplo (rutas) | |
| BE-06 Telemetría IoT | FE-06 Kits de sensores | |
| BE-09 Comunicaciones | FE-07 Administración de GPS | |
| | FE-08 Comunicaciones | |

**Hito B (02-12):** primera ruta real ejecutada por un chofer con la app y su GPS visible en el panel; al menos un sensor reportando desde un contenedor real. **Desde aquí, rutas reales todos los días hábiles** para acumular datos de KPI.

### Sprint C — "KPI, pruebas y demo final" (03-12 al 09-12)

| Back-end | Front-end | IoT / Documentación |
|---|---|---|
| BE-13 KPI con metas | FE-10 Tablero de KPI con metas | DOC-02 Diagramas actualizados |
| BE-14 Alertas y exportación | FE-04 Eliminar datos de ejemplo (KPI) | DOC-03 Guion de la demo y ensayos (3 a 5, cronometrados) |
| BE-15 Cuentas y datos de demo | FE-13 Pruebas sin datos simulados | |
| BE-17 Pruebas de rendimiento y seguridad | | |
| BE-18 Retención de datos GPS | | |

**Hito C (09-12):** la demo completa ensayada al menos 3 veces; cuentas de prueba listas; diagramas corregidos.

**Cierre:** 11-12-2026 (fecha de la demo final por confirmar).

## 4. Riesgos

| Riesgo | Impacto | Mitigación |
|---|---|---|
| Las coordenadas de los puntos aún no están digitalizadas | Sin puntos reales en el mapa | Completar **esta semana** el CSV de importación (formato en BE-04); plan B: captura manual en FE-05 |
| Solo dos sensores para cientos de puntos | La ruta "dinámica" casi no excluye puntos | Explicitarlo (ADR-0013): exclusión donde hay datos, calendario donde no; KPI-04 mide el problema |
| Choferes o empresa sin disponibilidad para rutas reales | KPI vacíos | Coordinar con los dueños desde el Sprint A; como mínimo, recorridos reales hechos por el equipo con la app, declarados como tales |
| Componentes del sensor sin llegar a tiempo | Sin piloto IoT | Comprar esta semana (DOC-01); un nodo funcional en un contenedor de prueba documentado vale más que dos a medias |
| OSRM pesado para el servidor | Rutas lentas | Recortar el extracto a Temuco; usar el modo degradado por proximidad |
| Servidor sin HTTPS | Android bloquea HTTP en claro; ESP32 sin TLS | Dominio + certificado (Let's Encrypt o ACM) en BE-16 |

## 5. Después de la entrega (no comprometido)

- Ampliar el piloto de sensores a más puntos; evaluar LTE-M/NB-IoT donde no haya Wi-Fi (ADR-0007).
- Notificaciones push cuando un punto llegue a `lleno` o una licencia esté por vencer.
- Replicación de la base de datos en una segunda zona de disponibilidad.
- Especificación OpenAPI generada (BE-19).
- Uso de los KPI históricos para ajustar umbrales y frecuencias por sector.
