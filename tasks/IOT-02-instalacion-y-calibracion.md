# IOT-02 · Instalación, calibración y prueba en terreno del piloto

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Maximiliano |
| Depende de | IOT-01, BE-05, BE-06, BE-16 |
| Origen | Piloto de dos nodos en contenedores reales; criterios de hardware: error ≤ ±5 pp, reconexión ≤ 2 min; evidencia fotográfica de cómo se mide |

## Objetivo
Al menos un nodo (idealmente dos) instalado en un contenedor real de Somos Terranova, calibrado, registrado en el sistema y reportando a staging, con la evidencia documentada para la demo.

## Pasos sugeridos
1. Elegir con la empresa 1 o 2 puntos **con Wi-Fi disponible y autorización** del recinto (ADR-0007). Anotar el tipo de contenedor (p. ej. campana de vidrio o contenedor de plástico) y sus dimensiones.
2. Montaje:
   - sensor en la parte superior, apuntando hacia abajo, lejos de las paredes (considerar el ángulo del haz) y protegido de la lluvia;
   - carcasa con grado de protección adecuado para exterior;
   - fijación que no impida vaciar el contenedor.
3. Medir con cinta métrica la **altura en vacío** (sensor → fondo) y la **zona ciega**, y registrarlas en el dispositivo (BE-05).
4. **Calibración:** con el contenedor vacío y con niveles de referencia (p. ej. 25 %, 50 % y 75 % de la altura útil, simulados con una superficie a esa distancia), anotar 10 lecturas por nivel y calcular el error en puntos porcentuales.
5. Pruebas en terreno: reconexión (cortar el Wi-Fi 5 min), autonomía (voltaje de la batería durante varios días), comportamiento con lluvia o sol si ocurre.
6. **Evidencia para la demo:** fotografías del montaje, un diagrama acotado de cómo se mide (sensor, haz, altura, nivel) y la tabla de calibración.
7. Documentar todo en `iot-esp32/docs/piloto.md`.

## Criterios de aceptación
- [ ] Error de medición ≤ ±5 puntos porcentuales frente a la referencia.
- [ ] Reconexión automática ≤ 2 min después de volver el Wi-Fi.
- [ ] El punto real cambia de estado en el mapa del panel a partir de las lecturas del sensor.
- [ ] Al menos 5 días de lecturas reales en staging antes de la demo final.
- [ ] Fotos, diagrama y tabla de calibración disponibles para la presentación.

## Archivos probables
`iot-esp32/docs/piloto.md`, `iot-esp32/docs/img/*`.
