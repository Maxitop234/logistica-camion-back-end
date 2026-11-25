# Firmware del nodo sensor (ESP32 + HC-SR04)

Mide la distancia al contenido del contenedor, la envía a la API y duerme. El servidor convierte la distancia en % de llenado con la calibración del sensor (altura del contenedor y distancia de lleno), así que el nodo **no corrige** valores.

Proyecto PlatformIO, framework Arduino, placa `esp32dev`.

## Ciclo

1. Despierta, mide la distancia (mediana de 5 muestras con eco) y la batería (promedio de 8 lecturas del ADC).
2. Se conecta al Wi-Fi y pone la hora por NTP (se conserva durante el deep sleep).
3. Envía `POST {API_URL}/api/v1/iot/lecturas` con `Authorization: Bearer {IOT_TOKEN}` y:
   ```json
   {"distancia_cm": 41.5, "bateria_voltaje": 3.87, "lectura_uuid": "5b3c2a30-…", "medido_en": "2026-11-25T18:00:00Z"}
   ```
   Si ninguna muestra tuvo eco envía `"distancia_cm": null, "error": "sin_eco"` (el servidor lo exige para el diagnóstico).
4. Duerme `INTERVALO_SEG` (300 s por defecto) descontando lo que tardó el ciclo.

**Sin red:** la lectura queda en la memoria no volátil (NVS, sobrevive a cortes de energía) y se reenvía de la más antigua a la más nueva en el siguiente ciclo con conexión. Caben 50; al llenarse se descarta la más antigua. Reenviar es seguro: el servidor ignora un `lectura_uuid` repetido (responde 200).

| Respuesta | Qué hace el nodo |
|---|---|
| 200 / 201 | La quita de las pendientes |
| 422 | La descarta (el servidor nunca la aceptará) y sigue con la próxima |
| Sin red, 401, 403, 409, 429, 5xx | Conserva todas y reintenta en el próximo ciclo |

Cada lectura espera hasta **90 s** a que el servidor gratuito (Render) despierte (tarda cerca de 1 min). `HTTPClient` acepta como máximo 65 s por intento, así que dentro de esos 90 s se reintenta ante un timeout o un 502/503/504 (hasta 3 intentos, con 3 s de pausa).

## Estructura

| Archivo (`src/`) | Responsabilidad |
|---|---|
| `main.cpp` | Ciclo: medir → encolar → conectar → enviar pendientes → apagar radio → dormir. Elige el transporte |
| `medicion.*` | HC-SR04 (mediana) y batería (ADC) |
| `lectura.*` | La lectura, su UUID v4 y su JSON (común a todos los transportes) |
| `pendientes.*` | Cola en NVS; envía a través de la interfaz `Transporte` |
| `sueno.*` | Deep sleep descontando la duración del ciclo |
| `reloj.*` | Hora UTC del nodo (la pone en hora el transporte) |
| `transporte.h` | Interfaz `Transporte`: `conectar()`, `enviar(lectura)`, `apagar()` y `ResultadoEnvio` |
| `transporte_wifi_https.*` | Implementación actual: Wi-Fi, NTP y POST HTTPS con reintentos |

**Agregar un transporte celular (pendiente):** crear `transporte_celular.h/.cpp` con una clase que herede de `Transporte` (`conectar()` enciende el módem, se registra en la red y pone la hora; `enviar()` hace el POST con `lecturaAJson` y traduce la respuesta a `ResultadoEnvio`; `apagar()` apaga el módem) y cambiar la instancia `transporte` en `main.cpp`. La medición, la cola y el sueño no cambian.

## Diagrama de pines

```
            ESP32 DevKit (esp32dev)
          ┌─────────────────────────┐
          │                         │
 HC-SR04  │                         │
 ┌──────┐ │                         │
 │ VCC  ├─┼──────────────── VIN (5 V)
 │ TRIG ├─┼──────────────── GPIO 5
 │ ECHO ├─┼──┬─[ 1 kΩ ]──┬─ GPIO 18      ECHO sale a 5 V: el divisor
 │ GND  ├─┼─┐│           │               1 kΩ / 2 kΩ lo baja a ~3,3 V
 └──────┘ │ ││         [ 2 kΩ ]
          │ ││           │
          │ └┴───────────┴─ GND
          │                         │
 Batería  │                         │
 (+) ─────┼─[ 100 kΩ ]──┬── GPIO 34 (ADC1, solo entrada)
          │             │
          │          [ 100 kΩ ]     divisor 1:2 → FACTOR_DIVISOR_BATERIA = 2.0
          │             │
 (−) ─────┼─────────────┴── GND
          └─────────────────────────┘
```

| Señal | Pin ESP32 | Nota |
|---|---|---|
| HC-SR04 TRIG | GPIO 5 | 3,3 V basta para disparar |
| HC-SR04 ECHO | GPIO 18 | **Siempre con divisor** (1 kΩ en serie, 2 kΩ a GND): 5 V directo daña el pin |
| HC-SR04 VCC | VIN / 5 V | El HC-SR04 clásico necesita 5 V (la variante HC-SR04P funciona desde 3 V) |
| Batería (+) | GPIO 34 | Divisor 100 kΩ / 100 kΩ; si cambias las resistencias, ajusta `FACTOR_DIVISOR_BATERIA` en `include/config.h` |
| GND | GND | Común a sensor, batería y ESP32 |

La alimentación definitiva desde batería (regulador, consumo en deep sleep de una placa DevKit, autonomía) está **POR CONFIRMAR** con el hardware final.

## Configuración

```bash
cp include/secrets.example.h include/secrets.h   # ignorado por git
```

| Constante | Dónde | Valor |
|---|---|---|
| `WIFI_SSID`, `WIFI_PASSWORD` | `secrets.h` | Red Wi-Fi 2,4 GHz |
| `API_URL` | `secrets.h` | Sin barra final, p. ej. `https://<servicio>.onrender.com` |
| `IOT_TOKEN` | `secrets.h` | Token del sensor (ver abajo) |
| `INTERVALO_SEG` | `secrets.h` | 300 en operación; 15 para probar |
| `TEST_ONLY` | `secrets.h` | Desactiva la validación del certificado. Solo para un banco de pruebas |
| Pines, muestras, timeouts | `include/config.h` | Ver comentarios |

**HTTPS:** se valida el certificado del servidor con las raíces públicas de `include/raices_ca.h` (Google Trust Services R1, R3 y R4, que usa Render, y Let's Encrypt ISRG X1 y X2). La validación necesita la hora: si NTP falla, la lectura queda pendiente y se reintenta. Si el servidor pasa a otra autoridad, hay que agregar su raíz en ese archivo.

## Obtener el token

El token se entrega una sola vez, al aprobar el sensor en el panel (sección «Sensores»). Con la etiqueta del sensor (código de hardware y código de vinculación, generados con `php artisan iot:generar-etiquetas`):

```bash
BASE=https://<servicio>.onrender.com/api/v1
curl -s -X POST $BASE/iot/vinculacion/anunciar -H 'Content-Type: application/json' \
  -d '{"codigo_hardware":"ECO-0001","codigo_vinculacion":"XXXX-XXXX-XXXX","firmware_version":"0.1.0"}'
# El administrador aprueba el sensor en el panel y elige su punto.
curl -s -X POST $BASE/iot/vinculacion/estado -H 'Content-Type: application/json' \
  -d '{"codigo_hardware":"ECO-0001","codigo_vinculacion":"XXXX-XXXX-XXXX"}'
# → "token": "eco_iot_…" (solo la primera vez): cópialo en IOT_TOKEN.
```

Si se pierde, el administrador usa «Rotar token» en el detalle del sensor.

## Compilar, subir y ver el monitor

```bash
pio run                 # compilar
pio run -t upload       # subir por USB
pio device monitor      # 115200 baudios
```

Ejemplo de un ciclo en el monitor serie:

```
[medicion] distancia=41.6 cm · bateria=3.91 V
[wifi] conectado a MiRed · IP 192.168.1.40 · RSSI -58 dBm
[http] 5b3c2a30-8c1f-4a52-9d1e-0c9a3a7f2b10 -> HTTP 201
[cola] lecturas pendientes: 0
[ciclo] durmiendo 9 s
```

Para ver de nuevo el arranque tras el deep sleep el monitor debe quedar abierto (el ESP32 se reinicia en cada ciclo).

## Diferencias con el contrato completo

`docs/IOT_FIRMWARE_CONTRATO.md` describe además la vinculación automática desde el nodo, el intervalo entregado por el servidor (`configuracion.intervalo_lectura_seg`) y el envío de `rssi_dbm`. Esta versión usa un token fijo en `secrets.h` y un intervalo propio. La alerta «sin reportar» del panel usa el intervalo configurado en el servidor (3600 s por defecto): con 300 s en el nodo, conviene fijar ese mismo valor en el sensor desde el panel.
