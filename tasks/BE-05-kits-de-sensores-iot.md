# BE-05 · Kits de sensores IoT: registro, token y estado

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Nelson |
| Depende de | BE-03 |
| Origen | Sección de administración de los kits de sensores; ADR-0012 |

## Objetivo
El administrador registra cada kit (ESP32 + sensor), lo asocia a un punto, obtiene su token y ve su estado (batería, última lectura, señal).

## Alcance
- Incluye: tabla `dispositivos`, CRUD `/dispositivos`, generación y rotación de token, foto de instalación, cálculo periódico del estado.
- No incluye: recepción de lecturas (BE-06).

## Pasos sugeridos
1. Migración `create_dispositivos_table` según `docs/DATA_MODEL.md` (`punto_id` único y nullable, `token_hash` único).
2. `Dispositivo::generarToken(): string` crea `Str::random(48)`, guarda `hash('sha256', $token)` y devuelve el token en claro **una sola vez**.
3. CRUD (rol administrador). `POST` responde `{data, token}`; `POST /dispositivos/{id}/rotar-token` también. Las dos acciones quedan en `audit_logs` (`token_iot_rotado`), sin el token.
4. Subida opcional de la foto de instalación (disco privado, JPG o PNG, ≤ 5 MB), visible con una URL firmada temporal.
5. Comando programado `dispositivos:actualizar-estado` (cada 5 min): `sin_reporte` si no hay lectura en `IOT_MINUTOS_SIN_REPORTE`; `bateria_baja` bajo `config('iot.bateria_minima_v')` (por defecto 3,4 V; confirmar con la batería elegida en DOC-01).
6. `config/iot.php` con los umbrales; documentar las variables nuevas en `.env.example`.

## Criterios de aceptación
- [ ] Al crear un dispositivo el token aparece una sola vez; `GET` nunca lo devuelve.
- [ ] Rotar el token invalida el anterior (se prueba junto con BE-06).
- [ ] No se pueden asociar dos dispositivos al mismo punto (422).
- [ ] El estado cambia a `sin_reporte` cuando pasa el umbral (prueba con `travel()`).
- [ ] `docs/API.md` §4.3 coincide con la implementación.

## Pruebas
`DispositivoApiTest` (CRUD, token, roles, unicidad) y `ActualizarEstadoDispositivosTest`.

## Archivos probables
`database/migrations/*_create_dispositivos_table.php`, `app/Models/Dispositivo.php`, `app/Http/Controllers/Api/V1/DispositivoController.php`, `app/Http/Requests/Dispositivo/*`, `app/Http/Resources/DispositivoResource.php`, `app/Console/Commands/ActualizarEstadoDispositivos.php`, `config/iot.php`.
