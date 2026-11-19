# DOC-01 · Ficha técnica y costos (sensor, batería, comunicaciones, servidor)

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Maximiliano (con Nelson en costos de servidor y datos) |
| Depende de | — (se completa con mediciones de IOT-01, IOT-02 y BE-09) |
| Origen | Respaldar con datos verificables cada decisión de hardware, comunicaciones y costos (sensor, batería, alcance, gateway, permisos, costo por sensor, costo mensual de datos e internet, servidor de la API) |

## Objetivo
Un documento único, con fuentes y fechas, que responde las preguntas técnicas y de costos del sistema. Archivo de salida: `docs/HARDWARE_Y_COSTOS.md`.

## Regla
**No inventar cifras.** Cada valor lleva su fuente: hoja de datos (enlace), cotización (tienda y fecha) o medición propia (fecha y método). Lo que no se tenga queda `POR CONFIRMAR`.

## Contenido mínimo
1. **Sensor de nivel**
   - Modelo elegido (HC-SR04 o JSN-SR04T) y por qué.
   - Rango nominal según la hoja de datos (HC-SR04: 2–400 cm) y **rango efectivo medido** en el contenedor real.
   - Ángulo del haz, zona ciega, resolución, efecto de la temperatura y la humedad, resistencia al agua.
   - Cómo se calcula el porcentaje (fórmula de `AGENTS.md`) y si la lectura es instantánea o periódica, con su intervalo.
2. **Microcontrolador y energía**
   - ESP32 (placa exacta) y su consumo **medido** en transmisión y en deep sleep. Ojo: las placas DevKit pueden consumir varios mA dormidas por el regulador y el chip USB.
   - Batería elegida (tipo, voltaje nominal, capacidad), cargador o protección, panel solar si aplica.
   - Cómo se mide la carga (divisor + ADC → voltaje → porcentaje con la curva de la celda).
   - **Autonomía estimada:** `capacidad_mAh / corriente_promedio_mA`, con la corriente promedio ponderada por el ciclo de trabajo.
3. **Comunicaciones (ADR-0007)**
   - Tabla Wi-Fi / LTE-M o NB-IoT / LoRaWAN con alcance, infraestructura, **gateway** (dónde se instalaría y qué permisos implica: techo propio, poste municipal o de la distribuidora), costo del módulo y costo mensual.
   - Respuesta a "¿por qué no GSM directo a la API?".
   - **Consumo medido** por sensor y por teléfono (BE-09), más una estimación con cabeceras TLS/TCP.
4. **Costos**
   - Lista de materiales por nodo (cotizada), instalación (carcasa, fijaciones, horas) y costo total por punto.
   - Mensual: servidor (EC2/RDS o el host real de BE-16), dominio, datos móviles de los choferes y SIM M2M si aplica.
   - Internet del recinto ($0 marginal si se usa el Wi-Fi existente; dejar constancia del acuerdo).
5. **Servidor de la API:** dónde está, tecnología (Laravel 13, PHP 8.5, Nginx, PostgreSQL/PostGIS, OSRM, Docker) y cómo se respalda.
6. **Tabla "pregunta técnica → respuesta → fuente"** que cubra los puntos 1 a 5.

## Criterios de aceptación
- [ ] Cada pregunta técnica o de costos de la tabla tiene una respuesta con su fuente.
- [ ] Hay al menos dos cotizaciones con fecha para los componentes principales.
- [ ] Los consumos de energía y de datos son medidos, no supuestos (o están marcados como estimación con su método).
- [ ] El documento se enlaza desde el README y se usa en DOC-02 y DOC-03.
