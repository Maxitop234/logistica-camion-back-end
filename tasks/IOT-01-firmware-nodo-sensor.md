# IOT-01 · Firmware ESP32 del nodo sensor

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Maximiliano |
| Depende de | Contrato de BE-06 (`docs/API.md` §4.4); para probar de punta a punta: BE-06 y BE-16 |
| Origen | Nodo sensor ultrasónico + ESP32; ADR-0007, ADR-0012; poder explicar cómo se calcula el porcentaje y cómo se mide la batería |

## Objetivo
Un firmware que mide el nivel del contenedor de forma robusta, informa batería y señal, envía las lecturas por HTTPS a la API con su token, tolera cortes de red y duerme entre lecturas para ahorrar batería.

## Alcance
- Incluye: proyecto PlatformIO en `iot-esp32/`, medición con mediana, transporte Wi-Fi + HTTPS separado de la medición, buffer en memoria RTC, deep sleep, medición de batería, `secrets.example.h` y README de armado.
- No incluye: transporte celular o LoRaWAN (dejar la interfaz preparada, ADR-0007).

## Pasos sugeridos
1. `iot-esp32/platformio.ini` (`board = esp32dev`, `framework = arduino`) con un entorno `native` para pruebas unitarias.
2. **Medición** (`Medidor`): pulso de disparo de 10 µs; `pulseIn` con timeout (≈ 30 ms); `distancia_cm = duración_µs × 0,0343 / 2`; 5 muestras separadas ~60 ms y **mediana**; descartar las lecturas fuera de 2–400 cm. Opcional: compensar la temperatura en la velocidad del sonido.
3. **Electrónica:**
   - el HC-SR04 funciona a 5 V y su pin ECHO entrega 5 V: usar un divisor de tensión o un *level shifter* hacia el GPIO de 3,3 V;
   - para exteriores, evaluar el JSN-SR04T (resistente al agua), compatible con el mismo protocolo de disparo y eco; la decisión va en DOC-01.
4. **Batería:** divisor resistivo hacia un pin **ADC1** (GPIO 32–39; ADC2 no funciona con Wi-Fi activo) y `analogReadMilliVolts`, con promedio de varias muestras. Enviar el voltaje; la API calcula el porcentaje.
5. **Transporte** (`Transporte` → `WifiHttps`):
   - `WiFiClientSecure` con el certificado raíz de la CA del servidor (p. ej. ISRG Root X1 si se usa Let's Encrypt), nunca `setInsecure()` en el firmware de la demo;
   - header `X-IoT-Token`; JSON del contrato con `rssi_dbm = WiFi.RSSI()`;
   - hora por NTP para `medido_en` (si no hay hora, omitir el campo).
6. **Resiliencia:** buffer `RTC_DATA_ATTR` de hasta 50 lecturas; reintento con espera creciente; si no hay red, guardar y dormir.
7. **Energía:** `esp_sleep_enable_timer_wakeup(intervalo)` usando `config.intervalo_lectura_seg` de la última respuesta. Apagar el sensor entre lecturas si el hardware lo permite.
8. `iot-esp32/include/secrets.example.h` (`WIFI_SSID`, `WIFI_PASS`, `API_URL`, `IOT_TOKEN`); el `secrets.h` real ya está ignorado por Git.
9. `iot-esp32/README.md`: diagrama de conexión (pines), lista de materiales, cómo compilar, cargar y ver el monitor serie.

## Criterios de aceptación
- [ ] Con un objeto a 50 cm y a 100 cm, la mediana reportada está a ±2 cm de la cinta métrica (anotar 10 mediciones por distancia).
- [ ] Una lectura aparece en el panel (BE-06 y FE-06) en < 5 min desde la medición.
- [ ] Al apagar el router 10 min, las lecturas se acumulan y se envían juntas al volver; ninguna se pierde.
- [ ] Se mide y anota la corriente en deep sleep y en transmisión de la placa real (insumo de DOC-01).
- [ ] `pio test -e native` pasa las pruebas de mediana y de conversión a distancia.

## Archivos probables
`iot-esp32/platformio.ini`, `iot-esp32/src/main.cpp`, `iot-esp32/src/{Medidor,Bateria,TransporteWifiHttps,Buffer}.{h,cpp}`, `iot-esp32/include/secrets.example.h`, `iot-esp32/test/test_medidor/*.cpp`, `iot-esp32/README.md`.
