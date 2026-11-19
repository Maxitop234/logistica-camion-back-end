# ADR-0011: Tiempo real mediante polling HTTP

- **Estado:** Aceptada
- **Fecha:** 2026-10-28
- **Relacionado con:** SLA de posición GPS ≤ 30 s; BE-07; FE-02, FE-03

## Contexto
El panel debe mostrar la posición de la flota con una latencia de 30 s como máximo. Hay pocos clientes simultáneos (≤ 5 choferes y ≤ 3 administradores en el piloto) y la app del chofer opera con señal intermitente.

## Decisión
- El teléfono envía `POST /gps/ping` cada 10–15 s (lotes si estuvo sin señal).
- El panel consulta `GET /gps/flota` cada 10–15 s. La respuesta es liviana: lee la última posición desnormalizada en `camiones`, sin JOIN sobre el histórico.
- Los timers se cancelan al salir de la vista (`ref.onDispose`).

## Alternativas consideradas
- **WebSockets (Laravel Reverb) o Server-Sent Events:** menor latencia, pero agregan un servidor y conexiones persistentes, y no aportan valor con 30 s de SLA y 8 clientes.
- **MQTT:** útil para IoT masivo; sobredimensionado para el piloto.

## Consecuencias
- Latencia en el peor caso ≈ intervalo del teléfono + intervalo del panel (≈ 30 s): cumple el SLA en el límite. Usar 10–12 s en ambos lados.
- Carga estimada: ≈ 0,5 peticiones por segundo en el piloto, muy por debajo de la capacidad de un `t3.medium`.
