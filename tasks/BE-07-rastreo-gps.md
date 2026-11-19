# BE-07 · Rastreo GPS: ping, flota en vivo y traza

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Maximiliano |
| Depende de | BE-11 (rutas `en_curso`) |
| Origen | SLA GPS ≤ 30 s; ADR-0004, ADR-0011; reemplazar la flota simulada por posiciones reales |

## Objetivo
La posición real del teléfono del chofer llega a la API y el panel ve la flota con una latencia de 30 s como máximo.

## Alcance
- Incluye: tabla `ubicaciones_gps`, `POST /gps/ping` (con lotes), `GET /gps/flota`, `GET /rutas/{ruta}/traza-gps`, actualización de la última posición en `camiones`.
- No incluye: el registro de teléfonos rastreadores (BE-08; esta tarea deja listo el hook).

## Pasos sugeridos
1. Migración `create_ubicaciones_gps_table` (`docs/DATA_MODEL.md`) con los índices indicados.
2. `POST /gps/ping` (rol chofer):
   - exige que el chofer tenga una ruta `en_curso` (si no, `409 "No hay una ruta en curso"`) y usa el camión de esa ruta (el cliente no lo elige);
   - acepta `posiciones[]` (máximo 100), valida las coordenadas en la zona, `precision_m ≥ 0` y que `capturado_en` no esté a más de 5 min en el futuro;
   - inserta las filas y actualiza `camiones.ultima_*` con la posición más reciente si es posterior a la guardada;
   - limitación de 30 peticiones por minuto por usuario.
3. `GET /gps/flota` (rol administrador): una consulta sobre `camiones` + chofer + ruta del día con el conteo de paradas (sin leer `ubicaciones_gps`). Incluye `segundos_desde_ultimo_ping` y `generado_en`.
4. `GET /rutas/{ruta}/traza-gps` en orden cronológico, simplificada si supera 2.000 puntos.
5. Actualizar `docs/API.md` §4.5.

## Criterios de aceptación
- [ ] Un ping sin ruta en curso → 409 y no se guarda nada.
- [ ] Un chofer no puede enviar posiciones de otro camión (el camión sale de su ruta).
- [ ] Un lote de 10 posiciones (envío offline) se guarda completo y `camiones` queda con la más reciente.
- [ ] `/gps/flota` responde en < 200 ms con 5 camiones y 100 000 filas en `ubicaciones_gps` (prueba con factories).
- [ ] Prueba manual con un teléfono real contra staging: la posición aparece en el panel en ≤ 30 s (registrar fecha y resultado en esta ficha).

## Pruebas
`GpsPingTest`, `GpsFlotaTest`, `TrazaGpsTest`.

## Archivos probables
`database/migrations/*_create_ubicaciones_gps_table.php`, `app/Models/UbicacionGps.php`, `app/Http/Controllers/Api/V1/GpsController.php`, `app/Http/Requests/Gps/PingRequest.php`, `app/Http/Resources/FlotaResource.php`.
