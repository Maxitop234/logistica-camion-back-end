# Contrato de la API — EcoRuta Temuco (`/api/v1`)

Este documento es el **contrato entre el back-end, la app Flutter y el firmware ESP32**. Si cambias un endpoint, actualízalo en el mismo commit.
Los endpoints ⏳ son contratos propuestos: el cliente Flutter ya consume algunos (se indica en la tabla) y la tarea correspondiente debe respetarlos o, si los cambia, coordinar el cambio con la tarea FE asociada.

## 1. Convenciones

| Tema | Regla |
|---|---|
| URL base | `{APP_URL}/api/v1` (local: `http://localhost/api/v1`; emulador Android: `http://10.0.2.2/api/v1`) |
| Autenticación de usuarios | `Authorization: Bearer <token>` emitido por `POST /auth/login` (Laravel Sanctum) |
| Autenticación de sensores | `X-IoT-Token: <token del dispositivo>` (ADR-0012) |
| Identificación del teléfono | `X-Device-Id: <uuid de instalación>` en `/gps/*` y `/rutas/*` del chofer (BE-08) |
| Formato | JSON UTF-8. `Accept: application/json` obligatorio |
| Respuestas | Recurso o colección envueltos en `{"data": …}`. **Excepción histórica:** `POST /auth/login` responde sin envoltorio |
| Errores | `{"message": "…"}`; en validación (422) también `{"errors": {"campo": ["…"]}}` |
| Fechas | ISO 8601 en UTC (`2026-11-18T14:05:00Z`); fechas sin hora como `AAAA-MM-DD` |
| Coordenadas | WGS84, grados decimales con 7 decimales (`latitud`, `longitud`) |
| Idioma | Campos y mensajes en español |
| Roles | `administrador`, `chofer`, `sysadmin`. Cada grupo de rutas usa `auth:sanctum`, `active` y `role:<rol>` |

**Códigos de estado usados:** 200 OK · 201 creado · 204 sin contenido · 401 sin token o token inválido · 403 rol sin permiso o cuenta desactivada · 404 no existe · 409 conflicto de estado · 422 validación · 429 demasiados intentos.

## 2. Resumen de endpoints

| Método | Ruta | Rol | Estado | Tarea | Lo consume el front |
|---|---|---|---|---|---|
| POST | `/auth/login` | público | ✅ | — | ✅ |
| POST | `/auth/logout` | autenticado | ✅ | — | ✅ |
| GET | `/auth/me` | autenticado | ✅ (`ruta_activa` siempre `null`) | BE-11 | ✅ |
| GET/POST/GET/PUT/DELETE | `/camiones[/{camion}]` | administrador | ✅ | BE-12 (licencia requerida) | ✅ |
| GET/POST/GET/PUT/DELETE | `/choferes[/{chofer}]` | administrador | ✅ (el `motivo` del DELETE se ignora) | BE-02 | ✅ |
| GET/POST/PUT | `/choferes/{chofer}/licencia` | administrador | ⏳ | BE-12 | FE-09 |
| POST | `/choferes/{chofer}/licencia/verificar` | administrador | ⏳ | BE-12 | FE-09 |
| GET/POST/GET/PUT/DELETE | `/sectores[/{sector}]` | administrador | ✅ | BE-03 | FE-05 |
| GET/POST/GET/PUT/DELETE | `/puntos[/{punto}]` | administrador | ✅ | BE-03 | ✅ (`sector` por nombre; FE-05 debe pasar a `sector_id`) |
| POST | `/puntos/{punto}/reactivar`, `/sensor-token`, `/simular-lectura` | administrador | ✅ | — | ✅ |
| GET | `/chofer/puntos` | chofer | ✅ | — | ✅ |
| POST | `/notificaciones/puntos/{solicitud}/aprobar` | administrador | ✅ (`sector_id` opcional) | — | ✅ |
| GET/POST/GET/PUT/DELETE | `/dispositivos[/{dispositivo}]` | administrador | ⏳ | BE-05 | FE-06 |
| POST | `/dispositivos/{dispositivo}/rotar-token` | administrador | ⏳ | BE-05 | FE-06 |
| GET | `/dispositivos/{dispositivo}/lecturas` | administrador | ⏳ | BE-06 | FE-06 |
| POST | `/iot/telemetria` | dispositivo (`X-IoT-Token`) | ⏳ | BE-06 | firmware IOT-01 |
| POST | `/gps/ping` | chofer | ⏳ | BE-07 | FE-03 |
| GET | `/gps/flota` | administrador | ⏳ | BE-07 | FE-02 |
| GET | `/rutas/{ruta}/traza-gps` | administrador | ⏳ | BE-07 | FE-11 |
| GET | `/gps/dispositivos` | administrador | ⏳ | BE-08 | FE-07 |
| GET | `/comunicaciones/consumo` | administrador | ⏳ | BE-09 | FE-08 |
| POST | `/rutas/generar` | administrador | ⏳ | BE-10 | ✅ (cae a datos de ejemplo con 404, FE-04) |
| GET | `/rutas` | administrador | ⏳ | BE-10 | ✅ (cae a datos de ejemplo con 404, FE-04) |
| GET | `/rutas/{ruta}` | administrador | ⏳ | BE-10 | FE-11 |
| POST | `/rutas/{ruta}/cancelar` | administrador | ⏳ | BE-10 | FE-11 |
| GET | `/chofer/ruta-activa` | chofer | ⏳ | BE-11 | ✅ |
| POST | `/rutas/{ruta}/iniciar` | chofer | ⏳ | BE-11 | ✅ |
| POST | `/rutas/{ruta}/recolectar-punto` | chofer | ⏳ | BE-11 | — |
| POST | `/rutas/{ruta}/sincronizar-lote` | chofer | ⏳ | BE-11 | ✅ |
| POST | `/rutas/{ruta}/finalizar` | chofer | ⏳ | BE-11 | ✅ |
| GET | `/kpis/dashboard` | administrador | ⏳ | BE-13 | ✅ (cae a datos de ejemplo con 404, FE-04/FE-10) |
| GET/PUT | `/kpis/metas` | administrador | ⏳ | BE-13 | FE-10 |
| GET | `/kpis/alertas` | administrador | ⏳ | BE-14 | ✅ |
| GET | `/kpis/exportar` | administrador | ⏳ | BE-14 | ✅ |
| GET | `/api/user` (sin `v1`) | autenticado | ⚠️ resto del esqueleto; eliminar | BE-02 | — |

---

## 3. Endpoints implementados

### 3.1 `POST /auth/login`

Sin autenticación. Limitado a 5 intentos fallidos por minuto por combinación de correo e IP.

```json
{ "email": "admin@ecoruta.test", "password": "••••••••", "device_name": "web-chrome" }
```

**200**

```json
{
  "token": "1|q8…",
  "token_type": "Bearer",
  "role": "administrador",
  "user": { "id": 1, "email": "admin@ecoruta.test", "role": "administrador", "is_active": true,
            "perfil": { "id": 1, "nombre": "Administrador de Ejemplo", "cargo": "Jefe de Operaciones", "telefono": "+569…" } }
}
```

Errores: `401 Credenciales inválidas.` · `403 La cuenta está desactivada.` · `429 Demasiados intentos…` · `422` validación. Cada intento queda en `audit_logs` (`login` o `failed_login`).

### 3.2 `POST /auth/logout` · `GET /auth/me`

- `logout` revoca el token actual y responde `200 {"message": "Sesión cerrada."}`.
- `me` responde `{"data": UserResource}`. Para un chofer agrega `camion` (`{id, patente, estado_operativo}` o `null`) y `ruta_activa`, que hoy es siempre `null` (BE-11).

### 3.3 Camiones — `role:administrador`

**Recurso `Camion`**

```json
{
  "id": 3, "patente": "CC3333", "marca": "Mercedes-Benz", "modelo": "Atego", "anio": 2022,
  "capacidad_kg": "10000.00", "estado_operativo": "disponible",
  "chofer_actual": { "id": 3, "nombre": "Luis Ejemplo Muñoz" },
  "ultima_posicion": { "latitud": null, "longitud": null, "velocidad_kmh": "0.00", "actualizada_en": null }
}
```

| Acción | Cuerpo / reglas | Respuesta |
|---|---|---|
| `GET /camiones` | — | `200 {data: Camion[]}` ordenado por patente |
| `POST /camiones` | `patente` (req., ≤ 10, única, se guarda en mayúsculas), `marca`, `modelo`, `anio` (1980 a año+1), `capacidad_kg` (> 0) | `201 {data: Camion}` |
| `GET /camiones/{id}` | — | `200 {data: Camion}` |
| `PUT /camiones/{id}` | Mismos campos opcionales + `estado_operativo` (`disponible`, `en_ruta`, `mantenimiento`, `inactivo`) + `chofer_actual_id` (nullable; un chofer solo puede tener un camión) | `200 {data: Camion}` |
| `DELETE /camiones/{id}` | Cuerpo opcional `{motivo}` (hoy se ignora, BE-02) | `204`; `409` si el camión está `en_ruta`. Baja lógica: `inactivo` y libera al chofer |

### 3.4 Choferes — `role:administrador`

**Recurso `Chofer`**

```json
{
  "id": 1, "user_id": 2, "email": "chofer1@ecoruta.test", "is_active": true,
  "nombre": "Pedro Ejemplo Soto", "telefono": "+56922222220", "licencia_clase": "A4",
  "disponible": true, "camion_actual": { "id": 1, "patente": "AA1111" }
}
```

| Acción | Cuerpo / reglas | Respuesta |
|---|---|---|
| `GET /choferes` | — | `200 {data: Chofer[]}` |
| `POST /choferes` | `email` (único), `password` (≥ 8), `nombre`, `rut` (módulo 11 y único por HMAC; **no se devuelve**), `licencia_clase` (`A1`–`A5`, `B`–`F`; por defecto `A4`), `telefono` | `201`; crea `users` (rol `chofer`) y `choferes` en una transacción |
| `PUT /choferes/{id}` | `nombre`, `telefono`, `licencia_clase`, `disponible` | `200` |
| `DELETE /choferes/{id}` | Cuerpo opcional `{motivo}` (hoy se ignora, BE-02) | `204`; desactiva la cuenta, revoca sus tokens y libera el camión |

### 3.5 Sectores y puntos de reciclaje — `role:administrador` (BE-03)

**Recurso `Sector`**

```json
{
  "id": 9, "nombre": "Las Quilas, centro",
  "dias_recoleccion": [2, 5], "dias_recoleccion_nombres": ["martes", "viernes"],
  "periodicidad_semanal": 2, "dias_coinciden_con_periodicidad": true,
  "puntos_esperados": 31, "puntos_count": 40, "puntos_activos_count": 40,
  "descripcion": null, "activo": true
}
```

`dias_recoleccion` usa días ISO (1 = lunes … 7 = domingo). Si la frecuencia declarada no coincide con los días, **mandan los días** (`dias_coinciden_con_periodicidad = false` lo señala). Los 12 sectores reales de Somos Terranova se cargan con `SectorSeeder` (lo llaman `DatabaseSeeder` y `DemoSeeder`).

| Acción | Cuerpo / reglas | Respuesta |
|---|---|---|
| `GET /sectores` | Filtro opcional `?activo=1` | `200 {data: Sector[]}` ordenados por nombre |
| `POST /sectores` | `nombre` (único), `dias_recoleccion` (arreglo 1–7 sin repetir, al menos uno), `periodicidad_semanal` (1–7), `puntos_esperados`, `descripcion` | `201` |
| `GET /sectores/{id}` | — | `200` |
| `PUT /sectores/{id}` | Los mismos campos, opcionales, y `activo`. Si cambian los días se recalcula la vigencia del estado de sus puntos | `200` |
| `DELETE /sectores/{id}` | Baja lógica (`activo = false`) | `200`; `409` si tiene puntos activos |

**Recurso `Punto`**

```json
{
  "id": 12, "nombre": "Condominio Los Avellanos", "nombre_automatico": false,
  "direccion": "Los Avellanos 1554, Temuco", "tipo_punto": "condominio", "tipo_material": null,
  "sector_id": 9, "sector": "Las Quilas, centro", "sector_resuelto": "Las Quilas, centro",
  "latitud": -38.749784, "longitud": -72.609664,
  "fuente_ubicacion": "manual", "requiere_revision": false, "nota": null, "codigo_externo": null,
  "porcentaje_llenado": 78, "estado_llenado": "alto", "origen_actualizacion": "manual",
  "ultima_actualizacion_estado": "2026-11-23T12:00:00+00:00",
  "estado_vigente": true, "estado_vigente_hasta": "2026-11-25T02:59:59+00:00",
  "altura_contenedor_cm": 120.0, "tiene_sensor": false, "sensor_codigo": null,
  "bateria_voltaje": null, "ultimo_heartbeat_iot": null, "activo": true
}
```

- `estado_llenado` y `porcentaje_llenado` son `null` en un punto **nunca medido** ("sin medición"); nunca se informa como `vacio`.
- `estado_vigente` indica si el estado aún vale (ADR-0013). Si es `false`, el punto cuenta como **desconocido** y entra a la ruta por el calendario de su sector. La vigencia depende de `origen_actualizacion`: `sensor_iot` → `ESTADO_SENSOR_VIGENCIA_HORAS`; `manual` y `chofer` → hasta el fin del siguiente día de recolección del sector; `recoleccion` → hasta el fin de la víspera del siguiente día de recolección. Los días se interpretan en `ZONA_HORARIA_OPERACION` (America/Santiago).
- `sector` es el nombre del sector (o `null`); `sector_resuelto` es el nombre o `"Sin sector"`.

| Acción | Cuerpo / reglas | Respuesta |
|---|---|---|
| `GET /puntos` | Filtros (listas separadas por coma): `estado_llenado=alto,lleno` (también `sin_medicion`; con este filtro solo se devuelven activos salvo que se indique `activo`), `sector_id=2,5`, `activo`, `con_dispositivo`, `requiere_revision`, `estado_vigente` (`0` = desconocidos), `solo_activos` | `200`; `422` con un valor de filtro no válido |
| `POST /puntos` | `nombre`, `direccion`, `tipo_punto` (`condominio`, `calle`), `latitud` y `longitud` **dentro de la zona de operación** (`ZONA_*`, Temuco con Labranza y Padre Las Casas), sector por `sector_id` o por nombre en `sector` (sin distinguir mayúsculas). Opcionales: `tipo_material` (`plastico`, `vidrio`, `carton`, `mixto`), `nota`, `porcentaje_llenado` o `estado_llenado`, `altura_contenedor_cm`, `sensor_codigo` | `201`. Sin nivel, el punto queda sin medición. Sin sector, se asigna el del punto activo más cercano y queda con `requiere_revision = true`. `422` si las coordenadas están fuera de la zona o el sector no existe |
| `GET /puntos/{id}` | — | `200` con sus últimas 15 `lecturas` |
| `PUT /puntos/{id}` | Datos del punto, `sector_id`/`sector`, `requiere_revision`, o ajuste manual del nivel: `porcentaje_llenado` (el estado se deriva) o solo `estado_llenado`. Queda con `origen_actualizacion = manual` y la hora actual. Mover el punto deja `fuente_ubicacion = manual` | `200` |
| `DELETE /puntos/{id}` | Baja lógica (`activo = false`) | `200`; `409` si está en una ruta `en_curso` |

`ubicacion` (PostGIS `geography(Point, 4326)`) se calcula en la base de datos a partir de `latitud` y `longitud`; no se expone.

---

### 3.6 Desvíos de ruta y calles problemáticas

La traza real ya se guarda en `telemetria_gps` (`POST /gps/ping`, `GET /rutas/{id}/traza`). Al finalizar una ruta (y al consultar los desvíos de una ruta en curso) se compara con `encoded_polyline`: un **desvío** es un tramo con lecturas a más de `DESVIO_UMBRAL_M` (40 m) de lo planificado que recorre al menos `DESVIO_LONGITUD_MINIMA_M` (100 m) fuera de ruta.

| Endpoint | Rol | Descripción |
|---|---|---|
| `GET /rutas/{ruta}/desvios` | administrador | Desvíos de la ruta: `inicio_en`, `fin_en`, `distancia_m`, `max_separacion_m`, `geometria_real` y `geometria_planificada` (polilíneas 1e5), motivo. |
| `GET /chofer/desvios` | chofer | Sus desvíos pendientes de revisión (`?todos=1` incluye los ya revisados). |
| `PUT /chofer/desvios/{id}/motivo` | chofer | `{motivo_codigo?, motivo_texto?, omitir?}`; el motivo es opcional, pero se debe enviar al menos un campo (o `omitir: true`). Códigos: `calle_otro_sentido`, `calle_cerrada_obras`, `no_pasa_camion`, `atajo_mas_rapido`, `otro`. `403` si el desvío es de otro chofer. |
| `GET /desvios/calles-problematicas?umbral=3&dias=90` | administrador | Tramos con al menos `umbral` desvíos (por defecto `DESVIO_UMBRAL_REPORTE`): cantidad, rutas y choferes distintos, motivos, comentarios y geometrías. |

Un tramo se identifica por la celda (~100 m) donde empieza y termina el desvío sobre la ruta planificada; es una aproximación (un mismo desvío cerca del borde de una celda puede quedar partido en dos claves).

## 4. Endpoints pendientes (contrato propuesto)

### 4.1 Licencias de conducir (BE-12)

`GET /choferes/{chofer}/licencia` → `{data: Licencia | null}`.
`POST /choferes/{chofer}/licencia` (multipart) crea una licencia nueva y deja la anterior como histórica:

| Campo | Regla |
|---|---|
| `clase` | `A1`–`A5`, `B`, `C`, `D`, `E`, `F` |
| `numero` | Opcional; en Chile suele coincidir con el RUT |
| `fecha_ultimo_control` | Fecha |
| `fecha_proximo_control` | Fecha futura (vencimiento) |
| `municipalidad` | Texto |
| `documento_licencia` | Archivo PDF, JPG o PNG ≤ `LICENCIAS_MAX_KB` (fotografía de ambas caras) |
| `certificado_hoja_vida` | Opcional, PDF del Registro Civil (hoja de vida del conductor) |

`POST /choferes/{chofer}/licencia/verificar` `{ "resultado": "verificada" | "rechazada", "observacion": "…" }`. Registra quién y cuándo verificó, y deja un registro en `audit_logs`.

**Recurso `Licencia`:** `{id, clase, fecha_proximo_control, municipalidad, estado: "pendiente"|"verificada"|"rechazada"|"vencida", verificada_por: {id, nombre}|null, verificada_en, observacion, documentos: {licencia_url, certificado_url}}`. Las URL son temporales y firmadas (5 min).

**Reglas de negocio:** no se puede asignar un camión (`PUT /camiones/{id}` con `chofer_actual_id`) ni generar una ruta para un chofer cuya licencia no esté `verificada`, esté vencida o no cubra el camión (por ejemplo, un camión con peso bruto vehicular sobre 3.500 kg requiere clase A4 o A5). En esos casos se responde `422` con un mensaje explícito.

### 4.2 Sectores y puntos de reciclaje (BE-03)

Implementado: ver §3.5.

### 4.3 Kits de sensores (BE-05)

**`Dispositivo`:** `{id, codigo_hardware, punto: {id, nombre}|null, tipo_sensor, altura_contenedor_cm, distancia_sensor_lleno_cm, intervalo_lectura_seg, conectividad: "wifi"|"lte_m"|"nb_iot"|"lorawan", firmware_version, bateria_voltaje, bateria_porcentaje, rssi_dbm, ultimo_reporte_en, estado: "activo"|"sin_reporte"|"bateria_baja"|"falla"|"inactivo", instalado_en, foto_instalacion_url}`.

- `POST /dispositivos` responde `201` con `{data: Dispositivo, token: "<token en claro>"}`. **El token se muestra solo esta vez**; la base de datos guarda su hash.
- `POST /dispositivos/{id}/rotar-token` invalida el token anterior y entrega uno nuevo.
- `GET /dispositivos/{id}/lecturas?desde&hasta` → serie de lecturas (paginada, 100 por página).

### 4.4 Telemetría IoT (BE-06)

`POST /iot/telemetria` con `X-IoT-Token`. Límite: 12 peticiones por minuto por dispositivo.

```json
{
  "lecturas": [
    { "distancia_cm": 41.5, "medido_en": "2026-11-18T13:55:00Z" }
  ],
  "bateria_voltaje": 3.87,
  "rssi_dbm": -67,
  "firmware_version": "0.3.0"
}
```

`lecturas` admite varias mediciones acumuladas en el buffer del nodo (máximo 50). **201**:

```json
{ "data": { "punto_id": 12, "porcentaje_llenado": 78, "estado_llenado": "alto", "lecturas_guardadas": 1 },
  "config": { "intervalo_lectura_seg": 300 } }
```

`401` token inválido · `403` dispositivo inactivo · `422` distancia fuera de rango (< 2 cm o > 400 cm, rango del sensor ultrasónico).

### 4.5 GPS (BE-07, BE-08)

`POST /gps/ping` (chofer, con `X-Device-Id`). Se rechaza con `409` si el chofer no tiene una ruta `en_curso` (rastreo solo durante la jornada).

```json
{
  "ruta_id": 55,
  "posiciones": [
    { "latitud": -38.7359000, "longitud": -72.5904000, "velocidad_kmh": 32.5, "rumbo_grados": 180,
      "precision_m": 8.0, "capturado_en": "2026-11-18T14:05:00Z" }
  ],
  "bateria_porcentaje": 64,
  "version_app": "1.2.0",
  "plataforma": "android",
  "permiso_ubicacion": "siempre"
}
```

`posiciones` admite el lote acumulado sin señal (máximo 100). Responde `201 {data: {guardadas: 1}}`.

`GET /gps/flota` (administrador, para polling cada 10–15 s):

```json
{ "data": [ {
  "camion": { "id": 1, "patente": "AA1111" }, "chofer": { "id": 1, "nombre": "Pedro …" },
  "estado_operativo": "en_ruta", "posicion": { "latitud": -38.73, "longitud": -72.59, "velocidad_kmh": 32.5, "rumbo_grados": 180 },
  "actualizada_en": "2026-11-18T14:05:00Z", "segundos_desde_ultimo_ping": 9,
  "ruta": { "id": 55, "paradas_completadas": 3, "paradas_totales": 8 }
} ], "generado_en": "2026-11-18T14:05:09Z" }
```

`GET /rutas/{ruta}/traza-gps` → `{data: [{latitud, longitud, velocidad_kmh, capturado_en}]}` en orden cronológico.

`GET /gps/dispositivos` (administración de GPS) → por teléfono: `{id, chofer, camion, plataforma, modelo, version_app, permiso_ubicacion, ultimo_ping_en, ultima_precision_m, ultima_bateria_porcentaje, pings_hoy, estado: "transmitiendo"|"sin_senal"|"inactivo"}`.

### 4.6 Comunicaciones (BE-09)

`GET /comunicaciones/consumo?desde=2026-11-01&hasta=2026-11-30&tipo=iot|movil` →

```json
{ "data": [ {
  "origen": { "tipo": "dispositivo_iot", "id": 4, "nombre": "ESP32-A1B2C3 · Condominio Los Pablos" },
  "peticiones": 2880, "bytes_entrada": 1474560, "bytes_salida": 576000,
  "promedio_bytes_por_peticion": 712, "rssi_promedio_dbm": -66, "errores": 3,
  "estimacion_mensual_mb": 2.1
} ], "nota_medicion": "Bytes de cuerpo HTTP medidos en el servidor; no incluye cabeceras TLS/TCP (ver ADR-0007)." }
```

### 4.7 Rutas (BE-10, BE-11)

**`Ruta`** (formato que ya consume `RutaActiva.fromJson` en el front):

```json
{
  "id": 55, "fecha_programada": "2026-11-19", "estado": "planificada",
  "camion": { "id": 1, "patente": "AA1111" }, "chofer": { "id": 1, "nombre": "Pedro …" },
  "modo_calculo": "osrm", "total_puntos_evaluados": 20, "puntos_incluidos_count": 8, "puntos_excluidos_count": 12,
  "distancia_estimada_km": 23.4, "duracion_estimada_min": 95, "encoded_polyline": "…",
  "hora_inicio_real": null, "hora_termino_real": null,
  "paradas": [ {
    "id": 301, "orden_parada": 1, "estado_parada": "pendiente", "estado_llenado_inicial": "lleno",
    "hora_estimada_llegada": "2026-11-19T11:20:00Z", "hora_real_recoleccion": null, "observaciones": null,
    "punto": { "id": 12, "nombre": "Condominio Los Pablos", "direccion": "…", "latitud": -38.73, "longitud": -72.62 }
  } ]
}
```

`encoded_polyline` usa el formato *Encoded Polyline* (precisión 5), el mismo que devuelve OSRM con `geometries=polyline`.

| Acción | Cuerpo | Reglas |
|---|---|---|
| `POST /rutas/generar` | `{camion_id, chofer_id, fecha_programada, sector_ids?: [..], incluir_punto_ids?: [..], excluir_punto_ids?: [..], origen?: {latitud, longitud}, destino?}` | Sin `sector_ids`, usa los sectores del día que aún no están en otra ruta de esa fecha (hay dos camiones por día). Inclusión según [ADR-0013](ADR/0013-estado-de-puntos-sin-sensor.md): estado vigente alto o lleno, o estado desconocido (calendario); los ajustes manuales del administrador quedan registrados. Sin `origen`, parte de la última posición GPS válida del chofer (precisión ≤ 50 m, últimas 24 h) y, si no hay, de la planta (`PLANTA_*`). Responde `422` si el camión tiene otro chofer asignado o el chofer tiene otro camión. Licencia verificada y vigente (BE-12). Una ruta activa por camión y fecha. `201` en ≤ 2 min |
| `GET /rutas` | `?fecha_programada=AAAA-MM-DD&estado=&chofer_id=&camion_id=` | `{data: Ruta[]}` con `paradas` (el front las usa en el listado; volumen bajo: pocas rutas por día) |
| `GET /chofer/ruta-activa` | — | `{data: Ruta}` del día para el chofer autenticado; `{data: null}` si no tiene |
| `POST /rutas/{id}/iniciar` | — | `planificada → en_curso`, `hora_inicio_real`, camión `en_ruta`; `409` si el estado no corresponde |
| `POST /rutas/{id}/recolectar-punto` | `{punto_id, sync_uuid, hora_real_recoleccion, nivel_observado?, observaciones?}` | Idempotente por `sync_uuid` |
| `POST /rutas/{id}/sincronizar-lote` | `{recolecciones: [{punto_id, sync_uuid, hora_real_recoleccion, nivel_observado?, observaciones?}]}` | Idempotente por elemento; responde `{data: {aplicadas: n, duplicadas: m}}` |
| `POST /rutas/{id}/finalizar` | — | `en_curso → completada`; paradas pendientes pasan a `omitido`; camión `disponible` |
| `POST /rutas/{id}/cancelar` | `{motivo}` | Solo `planificada` |

`nivel_observado` (`vacio`, `bajo`, `medio`, `alto`, `lleno`) es lo que el chofer ve al llegar; **obligatorio en la app**. Permite medir si la ruta visitó puntos que realmente lo necesitaban (KPI-04, ADR-0009 y ADR-0013).

Cada parada del recurso `Ruta` incluye además `motivo_inclusion`: `sensor`, `manual`, `observacion` o `calendario`.

### 4.8 KPI (BE-13, BE-14)

`GET /kpis/dashboard?fecha_inicio=2026-11-01&fecha_fin=2026-11-30&agrupacion=semana|mes|trimestre|semestre|anio&chofer_id=&camion_id=`

```json
{
  "data": {
    "periodo": { "desde": "2026-11-01", "hasta": "2026-11-30", "agrupacion": "semana" },
    "kpis": [ {
      "id": "KPI-01", "nombre": "Rutas completadas dentro del tiempo planificado",
      "formula": "rutas con duración real ≤ duración estimada × 1,15 / rutas completadas × 100",
      "numerador": 17, "denominador": 20, "valor": 85.0, "unidad": "%",
      "meta": 80.0, "comparador": ">=", "cumple": true,
      "serie": [ { "periodo": "2026-W45", "valor": 82.0, "cumple": true } ]
    } ],
    "metricas": {
      "tiempo_promedio_ruta_min": 128.4, "tiempo_promedio_entre_puntos_min": 11.2,
      "puntos_vaciados": 154, "desviacion_promedio_min": 6.3,
      "ranking_choferes": [ { "id": 1, "nombre": "Pedro …", "puntos": 48 } ],
      "ranking_camiones": [ { "id": 1, "patente": "AA1111", "puntos": 81 } ]
    },
    "datos_suficientes": true
  }
}
```

Las definiciones, fórmulas y metas de KPI-01 a KPI-06 están en [ADR-0009](ADR/0009-kpi-como-resultado-contra-meta.md). Con `denominador = 0`, `valor` y `cumple` son `null`: nunca se inventa un valor.

- `GET /kpis/metas` / `PUT /kpis/metas/{kpi_id}` `{meta, comparador, tolerancia}`: metas editables por el administrador.
- `GET /kpis/alertas?fecha_inicio&fecha_fin` → `{data: [{id, prioridad: "alta"|"media"|"baja", tipo, titulo, detalle, kpi_id, ruta_id?, creada_en}]}` (KPI bajo meta, tramos anómalos, sensores sin reporte, licencias por vencer).
- `GET /kpis/exportar?…&formato=pdf|csv` → archivo con `Content-Disposition: attachment; filename="kpi_2026-11.pdf"`.
