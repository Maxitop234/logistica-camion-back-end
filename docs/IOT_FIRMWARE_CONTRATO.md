# Contrato del firmware ESP32

Lo que debe hacer el firmware del nodo sensor (ESP32 + HC-SR04 o JSN-SR04T) para hablar con la API. No hay firmware aún: cuando exista, solo sigue este documento. Referencias: `docs/API.md` §4.3 y §4.4, ADR-0007 y ADR-0012. Los valores de zona ciega, alcance y consumo son de las hojas de datos y están **POR CONFIRMAR** con el hardware definitivo.

Todo va por HTTPS con `Content-Type: application/json`. Las fechas son ISO 8601 en UTC. `{BASE}` es la URL de la API (`https://…/api/v1`).

## 1. Datos del nodo

| Dato | Dónde vive | Notas |
|---|---|---|
| `codigo_hardware` | Flash, grabado al fabricar (p. ej. `ECO-0001`) | Igual al de la etiqueta |
| `codigo_vinculacion` | Flash, grabado al fabricar (p. ej. `K7QM-2XPD-9F4T`) | Secreto de la etiqueta. No se imprime en logs ni se envía en lecturas |
| `token` | NVS (memoria no volátil), **nunca** en el código fuente | Se obtiene en la vinculación |
| `intervalo_lectura_seg` | NVS | Lo entrega el servidor; por defecto 3600 |
| `tipo_sensor`, `conectividad` | Constantes del firmware | `hc_sr04` / `jsn_sr04t`; `wifi` / `lte_m` / `nb_iot` / `lorawan` |

## 2. Ciclo de vida

```
arranque ──► ¿hay token en NVS? ──sí──► MEDIR Y ENVIAR ──► deep sleep ──► arranque
                    │no
                    ▼
              ANUNCIARSE ──► ESPERAR APROBACIÓN (consultar estado) ──► guardar token ──► MEDIR Y ENVIAR
```

### 2.1 Anunciarse (sin token)

`POST {BASE}/iot/vinculacion/anunciar`

```json
{
  "codigo_hardware": "ECO-0001",
  "codigo_vinculacion": "K7QM-2XPD-9F4T",
  "firmware_version": "0.1.0",
  "conectividad": "wifi",
  "tipo_sensor": "jsn_sr04t"
}
```

| Respuesta | Qué hacer |
|---|---|
| `201` o `200` `{"estado":"pendiente","mensaje":"…","hora_servidor":"…"}` | Pasar a 2.2 |
| `403` `{"message":"Código de vinculación inválido."}` | La etiqueta no corresponde al hardware: no reintentar en bucle; dormir 1 h |
| `403` `{"message":"El sensor está desactivado."}` | Dormir 24 h y reintentar |
| `409` `{"message":"El sensor ya está vinculado…"}` | El servidor ya lo tiene activo: ir a 2.2 (si no hay token, el administrador debe rotarlo; ver 2.2) |
| `429` | Límite de intentos (10/min por IP, 20/min por hardware): dormir 5 min |

### 2.2 Esperar la aprobación y guardar el token

El administrador ve el sensor en "Sensores nuevos por vincular", ingresa o escanea el código de la etiqueta y elige el punto. Mientras tanto el nodo consulta cada 5 min (con deep sleep entre consultas):

`POST {BASE}/iot/vinculacion/estado`

```json
{ "codigo_hardware": "ECO-0001", "codigo_vinculacion": "K7QM-2XPD-9F4T" }
```

Pendiente:

```json
{ "estado": "pendiente", "token": null, "intervalo_lectura_seg": 3600, "endpoint_lecturas": "/api/v1/iot/lecturas", "hora_servidor": "2026-11-23T14:00:00Z" }
```

Aprobado (la **primera** consulta tras la aprobación):

```json
{ "estado": "activo", "token": "eco_iot_Xk3…(56 caracteres)", "intervalo_lectura_seg": 3600, "endpoint_lecturas": "/api/v1/iot/lecturas", "hora_servidor": "2026-11-23T14:05:00Z" }
```

- **El token llega una sola vez.** Guardarlo en NVS y confirmar la escritura antes de seguir. Si el nodo se apaga o pierde la respuesta antes de guardarlo, las siguientes consultas devuelven `"token": null`: el administrador debe pulsar "Rotar token" en el panel y el nodo lo retira de nuevo con esta misma consulta.
- Guardar también `intervalo_lectura_seg`.
- Estado `desactivado`: dormir 24 h y volver a consultar. Cuando el administrador lo reactiva, la consulta entrega un token nuevo.
- `403` código inválido, `404` el sensor aún no se anunció (volver a 2.1), `429` límite de intentos.

## 3. Medir

1. Alimentar el sensor y esperar su arranque (~100 ms).
2. Tomar **5 mediciones** separadas ~60 ms y usar la **mediana** (filtra el ruido acústico).
3. `distancia_cm = tiempo_de_eco_us / 58.0`, con 1 decimal. Si no hay eco (timeout), la medición falla.
4. Medir la batería (`bateria_voltaje`, divisor al ADC) y la señal (`rssi_dbm` al conectar).
5. No corregir ni descartar valores "raros" en el nodo: el **servidor** valida con la calibración del dispositivo. Una lectura en la zona ciega (HC-SR04 < 2 cm, JSN-SR04T < 20 cm), sin eco o más lejos que el fondo se guarda como inválida y no cambia el estado del punto (nunca cuenta como 100 %).

## 4. Enviar la lectura

`POST {BASE}/iot/lecturas` con el header `X-IoT-Token: <token>`.

```json
{
  "distancia_cm": 41.5,
  "bateria_voltaje": 3.87,
  "rssi_dbm": -67,
  "firmware_version": "0.1.0",
  "medido_en": "2026-11-23T14:00:00Z",
  "lectura_uuid": "5b3c2a30-8c1f-4a52-9d1e-0c9a3a7f2b10"
}
```

- `lectura_uuid`: UUID v4 generado **al medir** y conservado en los reintentos; hace idempotente el envío.
- `medido_en`: hora del nodo (sincronizada por NTP). Sin hora válida, omitirlo: el servidor usa la suya.
- Si la medición falló: `{"distancia_cm": null, "error": "sin_eco", "bateria_voltaje": 3.87, "rssi_dbm": -67, "lectura_uuid": "…"}`. Sí hay que enviarlo: sirve para el diagnóstico.

Respuesta `201` (lectura nueva) o `200` (`"duplicada": true`, el reintento ya estaba registrado):

```json
{
  "data": { "id": 12, "nombre": "Condominio Los Pablos", "porcentaje_llenado": 65, "estado_llenado": "medio", "...": "…" },
  "lectura": { "id": 90, "distancia_cm": 41.5, "porcentaje_llenado": 65, "estado_llenado": "medio", "...": "…" },
  "valida": true,
  "motivo_invalido": null,
  "duplicada": false,
  "configuracion": { "intervalo_lectura_seg": 3600 },
  "hora_servidor": "2026-11-23T14:00:02Z"
}
```

Lectura inválida: `201` con `"valida": false`, `"motivo_invalido": "zona_ciega"` (o `sin_eco`, `fuera_de_rango`) y `"lectura": null`. No reintentar: ya quedó registrada.

| Respuesta | Qué hacer |
|---|---|
| `200`/`201` | Borrar la lectura del buffer. Si `configuracion.intervalo_lectura_seg` cambió, guardarlo en NVS |
| `401` | Token inválido o rotado: ir a 2.2 (consultar estado) para retirar el token nuevo; conservar el buffer |
| `403` | Desactivado o punto inactivo: dormir 24 h; conservar el buffer |
| `409` | El sensor no tiene punto asignado: dormir 1 h; conservar el buffer |
| `422` | Cuerpo inválido (bug del firmware): descartar esa lectura, registrar el error localmente |
| `429`, `5xx`, sin red, timeout | Reintentar (sección 6); conservar el buffer |

## 5. Deep sleep

Tras enviar (o agotar los reintentos), dormir `intervalo_lectura_seg` menos lo que tardó el ciclo (despertar con temporizador RTC). Sin conexión a la red no se queda esperando: se mide, se guarda en el buffer y se duerme.

## 6. Reintentos y buffer sin conexión

- Buffer circular en flash (NVS o LittleFS) de **50 lecturas** como máximo (la más antigua se descarta primero). Cada entrada guarda `distancia_cm` (o `error`), `bateria_voltaje`, `rssi_dbm`, `medido_en` y `lectura_uuid`.
- En cada despertar: medir, agregar al buffer y enviar **en orden, de la más antigua a la más nueva**, hasta vaciarlo o fallar. Las lecturas atrasadas llevan su `medido_en` original; el servidor no deja que una atrasada pise un estado más reciente.
- Reintento inmediato con espera exponencial (2 s, 4 s, 8 s) hasta 3 intentos por ciclo; después dormir y continuar en el siguiente despertar.
- Respetar el límite de 120 peticiones por minuto: con un buffer grande, enviar con 500 ms entre peticiones.

## 6.1 Tiempos de referencia

| Acción | Valor |
|---|---|
| Intervalo de lectura por defecto | 3600 s (lo fija el servidor por sensor) |
| Consulta de estado mientras está pendiente | cada 300 s |
| Timeout HTTP | 15 s |
| Alerta "sin reportar" del panel | más de 2 intervalos sin conexión |
| Alerta "batería baja" | menos de 3,40 V |

## 7. Actualización OTA (futuro)

Fuera del alcance actual. Reservado: el campo `firmware_version` de cada petición permite al servidor conocer qué versión corre cada nodo, y `configuracion` de la respuesta de lecturas admitirá más campos sin romper el firmware (el firmware **ignora** claves que no conoce). Se propondrá un ADR cuando haya hardware.

## 8. Qué nunca hacer

- Poner `codigo_vinculacion` o el token en el código fuente, en logs por serie o en el repositorio.
- Enviar lecturas sin `X-IoT-Token`, o con un token de otro nodo.
- Corregir distancias en el nodo "para que den bien": la calibración (altura del contenedor, distancia de lleno) vive en el servidor y el administrador la ajusta desde el panel ("Calibrar vacío").
- Reintentar sin pausa ante un `403`, `409` o `429`.

## 9. Probar sin hardware

Con el servidor en modo demostración (`APP_DEMO_MODE=true`) los sensores simulados pasan por este mismo flujo, así que `curl` sirve de nodo de prueba:

```bash
BASE=http://localhost/api/v1
curl -s -X POST $BASE/iot/vinculacion/anunciar -H 'Content-Type: application/json' \
  -d '{"codigo_hardware":"ECO-0001","codigo_vinculacion":"K7QM-2XPD-9F4T","firmware_version":"0.1.0"}'
# (el administrador aprueba en el panel)
curl -s -X POST $BASE/iot/vinculacion/estado -H 'Content-Type: application/json' \
  -d '{"codigo_hardware":"ECO-0001","codigo_vinculacion":"K7QM-2XPD-9F4T"}'
curl -s -X POST $BASE/iot/lecturas -H 'Content-Type: application/json' -H "X-IoT-Token: $TOKEN" \
  -d '{"distancia_cm":41.5,"bateria_voltaje":3.87,"rssi_dbm":-67,"lectura_uuid":"5b3c2a30-8c1f-4a52-9d1e-0c9a3a7f2b10"}'
```

Las etiquetas de prueba se generan con `php artisan iot:generar-etiquetas 3 --lote=prueba`.
