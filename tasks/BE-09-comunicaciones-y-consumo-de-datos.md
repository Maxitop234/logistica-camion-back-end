# BE-09 · Comunicaciones: consumo de datos y calidad de enlace

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Nelson |
| Depende de | BE-06, BE-07 |
| Origen | Visibilidad del ancho de banda y del costo mensual de transferencia de datos; ADR-0007 |

## Objetivo
Medir con datos reales cuántas peticiones y bytes envía cada sensor y cada teléfono, con qué calidad de señal y con qué errores, y estimar el consumo mensual.

## Alcance
- Incluye: tabla `consumo_comunicaciones`, middleware `RegistrarConsumo` en `/iot/*`, `/gps/*` y las rutas del chofer, `GET /comunicaciones/consumo`.
- No incluye: medir el tráfico TLS/TCP real (se documenta como limitación y se estima en DOC-01).

## Pasos sugeridos
1. Migración `create_consumo_comunicaciones_table` con índice único `(fecha, origen_tipo, origen_id)`.
2. Middleware *terminable* (`terminate()`) que, después de responder, suma `peticiones`, `bytes_entrada` (`strlen($request->getContent())`), `bytes_salida` (`strlen($response->getContent())`), `errores` (status ≥ 400) y RSSI (en IoT) con un *upsert* incremental. No debe agregar latencia a la respuesta.
3. Origen: el `Dispositivo` (IoT) o el `DispositivoMovil` (GPS) resuelto por la autenticación.
4. `GET /comunicaciones/consumo?desde&hasta&tipo` con totales, promedio de bytes por petición, RSSI promedio y `estimacion_mensual_mb = promedio diario de bytes × 26 días operativos / 1 048 576`.
5. Incluir en la respuesta `nota_medicion` explicando que son bytes de cuerpo HTTP.

## Criterios de aceptación
- [ ] Después de 5 lecturas IoT, el consumo del dispositivo muestra 5 peticiones y los bytes correctos.
- [ ] Un 401 o un 429 cuenta como error del origen si se identificó; si no, no se registra.
- [ ] El endpoint filtra por fechas y tipo y coincide con `docs/API.md` §4.6.
- [ ] Los valores medidos en el piloto se copian a DOC-01.

## Pruebas
`ConsumoComunicacionesTest`.

## Archivos probables
`app/Http/Middleware/RegistrarConsumo.php`, `app/Models/ConsumoComunicacion.php`, `app/Http/Controllers/Api/V1/ComunicacionesController.php`, migración.
