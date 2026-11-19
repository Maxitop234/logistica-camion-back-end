# BE-06 · Ingesta de telemetría IoT y cálculo de llenado

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Nelson |
| Depende de | BE-05 |
| Origen | SLA de actualización de estado ≤ 5 min; ADR-0012 |

## Objetivo
Los nodos ESP32 envían lecturas a `POST /api/v1/iot/telemetria`, y el estado del punto se actualiza automáticamente en menos de 5 minutos.

## Contexto
El contrato está en `docs/API.md` §4.4; el firmware (IOT-01) se programa contra él. La fórmula y los umbrales están en `AGENTS.md` §4 y en `EstadoLlenado` (BE-03).

## Alcance
- Incluye: middleware `AutenticarDispositivo` (`X-IoT-Token`), endpoint con lotes, servicio `ProcesadorDeTelemetria`, tabla `lecturas_iot`, límite de peticiones y endpoint de lecturas por dispositivo.
- No incluye: registro de consumo de datos (BE-09; dejar un punto de extensión).

## Pasos sugeridos
1. Middleware: busca el dispositivo por `hash('sha256', header)` y compara con `hash_equals`. 401 si no existe; 403 si está `inactivo`. Deja `$request->attributes->set('dispositivo', …)`.
2. `RateLimiter::for('iot', …)`: 12 peticiones por minuto por dispositivo.
3. `ProcesadorDeTelemetria::procesar(Dispositivo, array $lecturas, ?float $bateria, ?int $rssi)`:
   - valida que la distancia esté entre 2 y 400 cm (rango del HC-SR04; ajustar si se usa otro sensor);
   - calcula el porcentaje con la altura y la zona ciega del dispositivo, y el estado con `EstadoLlenado`;
   - inserta `lecturas_iot` y actualiza el punto **solo si la lectura es más reciente** que `ultima_actualizacion_estado` (`origen_estado = sensor`);
   - actualiza en el dispositivo `bateria_voltaje`, `rssi_dbm`, `ultimo_reporte_en` y `firmware_version`.
4. Responder `201` con `config.intervalo_lectura_seg` del dispositivo (permite cambiar la frecuencia sin reprogramar).
5. `GET /dispositivos/{id}/lecturas?desde&hasta` paginado para el panel (FE-06).

## Criterios de aceptación
- [ ] Una lectura con token válido actualiza el punto: con altura 120 cm y distancia 30 cm → 75 % → `alto`.
- [ ] Un lote de 3 lecturas guarda 3 filas y deja el estado de la más reciente.
- [ ] Token inválido → 401; dispositivo inactivo → 403; la 13.ª petición en un minuto → 429.
- [ ] Una lectura más antigua que el estado manual vigente no lo sobrescribe.
- [ ] Tiempo de respuesta < 300 ms en local para una lectura.

## Pruebas
`TelemetriaIotTest` (casos anteriores, bordes del rango y rotación de token de BE-05) y `ProcesadorDeTelemetriaTest` (unitaria de la fórmula).

## Archivos probables
`app/Http/Middleware/AutenticarDispositivo.php`, `app/Http/Controllers/Api/V1/TelemetriaController.php`, `app/Services/Iot/ProcesadorDeTelemetria.php`, `app/Models/LecturaIot.php`, `database/migrations/*_create_lecturas_iot_table.php`, `bootstrap/app.php` (alias del middleware), `app/Providers/AppServiceProvider.php` (rate limiter).
