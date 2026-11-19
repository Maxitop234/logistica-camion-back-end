# BE-08 · Administración de GPS (teléfonos rastreadores)

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Maximiliano |
| Depende de | BE-07 |
| Origen | Sección de administración de los dispositivos GPS (los teléfonos de los choferes); ADR-0004 |

## Objetivo
El administrador ve qué teléfono rastrea cada camión, si está transmitiendo, con qué precisión, batería y permisos, y desde cuándo está sin señal.

## Alcance
- Incluye: tabla `dispositivos_moviles`, registro y actualización automáticos desde los pings, `GET /gps/dispositivos`, estado `transmitiendo` / `sin_senal` / `inactivo`, desactivación de un teléfono.
- No incluye: gestión remota del teléfono (MDM).

## Pasos sugeridos
1. Migración `create_dispositivos_moviles_table` y FK `dispositivo_movil_id` en `ubicaciones_gps`.
2. En `POST /gps/ping` y en las rutas del chofer: leer `X-Device-Id` (UUID obligatorio en `/gps/ping`, 422 si falta) y hacer *upsert* del teléfono con `plataforma`, `version_app`, `permiso_ubicacion`, `bateria_porcentaje`, `ultima_precision_m` y `ultimo_ping_en`.
3. `GET /gps/dispositivos` (administrador): una fila por teléfono con chofer, camión, últimos valores, `pings_hoy` y `estado` (`sin_senal` si el chofer tiene ruta en curso y no envía desde hace `GPS_MINUTOS_SIN_SENAL`).
4. `PATCH /gps/dispositivos/{id}` `{activo: false}`: los pings de un teléfono desactivado responden 403 (p. ej. teléfono perdido).
5. Generar la alerta `gps_sin_senal` (la consume BE-14).

## Criterios de aceptación
- [ ] El primer ping de un teléfono nuevo lo registra; los siguientes actualizan sus datos.
- [ ] Un chofer con ruta en curso y sin pings por más de `GPS_MINUTOS_SIN_SENAL` aparece `sin_senal`.
- [ ] Un teléfono desactivado recibe 403 en `/gps/ping`.
- [ ] La respuesta coincide con `docs/API.md` §4.5.

## Pruebas
`DispositivoMovilTest`, `AdministracionGpsTest`.

## Archivos probables
`database/migrations/*_create_dispositivos_moviles_table.php`, `app/Models/DispositivoMovil.php`, `app/Http/Controllers/Api/V1/DispositivoMovilController.php`, `app/Http/Resources/DispositivoMovilResource.php`.
