# ADR-0007: Conectividad del nodo IoT (Wi-Fi, celular o LoRaWAN)

- **Estado:** Propuesta
- **Fecha:** 2026-11-18
- **Relacionado con:** IOT-01, IOT-02, DOC-01, BE-09

## Contexto
- El diseño inicial define que el ESP32 envía las lecturas por **Wi-Fi** al punto de acceso del recinto. También se consideró **LoRaWAN**. La elección debe responder: alcance máximo, dónde va el gateway, permisos municipales, por qué no una red celular enviando a la API, y costo por sensor y por mes.
- Los puntos de Somos Terranova son de varios tipos (condominios, empresas, municipio, puntos en la calle). Solo algunos tienen Wi-Fi disponible.
- El piloto considera **dos nodos**.

## Opciones comparadas

| Criterio | Wi-Fi (ESP32 integrado) | Celular LTE-M / NB-IoT (p. ej. SIM7080G) | LoRaWAN (p. ej. SX1276/RFM95, AU915) |
|---|---|---|---|
| Infraestructura | AP del recinto (decenas de metros) | Red del operador; no requiere gateway | **Gateway** propio o de una red comunitaria con conexión a internet |
| Alcance típico | Decenas de metros | Cobertura del operador | 2–5 km en ciudad y más con línea de vista; depende de la ubicación del gateway |
| Permisos | Acuerdo con el dueño del recinto | Ninguno adicional (módulo certificado) | Lugar para la antena del gateway: techo propio, planta o poste (poste = permiso municipal o de la distribuidora) |
| Integración con la API | HTTPS directo (`POST /iot/telemetria`) | HTTPS directo | Paquetes pequeños (decenas a cientos de bytes, sin JSON). El servidor de red (TTN o ChirpStack) los reenvía por webhook: requiere un endpoint adicional |
| Costo del módulo | Incluido en el ESP32 | POR CONFIRMAR (cotizar) | POR CONFIRMAR, más el gateway |
| Costo mensual | $0 adicional (usa el internet del recinto) | Plan M2M por SIM: POR CONFIRMAR con operadores | $0 por nodo; el gateway necesita internet |
| Consumo | Alto al asociarse; mitigado con deep sleep | Bajo con PSM/eDRX | Muy bajo |

## Decisión (propuesta)
1. **Piloto (demo final): Wi-Fi**, como en el diseño inicial, instalando los dos nodos en puntos con Wi-Fi disponible y autorización del recinto. Es lo único demostrable con datos reales en el plazo.
2. **Escalamiento a puntos sin Wi-Fi: celular LTE-M/NB-IoT**, que envía a la misma API por HTTPS sin gateway ni permisos de instalación. El costo mensual por SIM se documenta en DOC-01.
3. **LoRaWAN solo si** muchos puntos quedan dentro del alcance de un gateway instalado en una propiedad de Somos Terranova (p. ej. la planta). Hoy no se justifica para dos nodos.
4. Se evitan las redes 2G/3G por su retiro progresivo; confirmar con los operadores.
5. El firmware separa la capa de transporte (`Transporte` con implementación `WifiHttps`), para cambiar a celular sin tocar la medición.
6. Cada lectura incluye `rssi_dbm`, y la API registra peticiones y bytes por nodo (BE-09), para responder "¿cómo está el ancho de banda?" con datos medidos.

## Consecuencias
- La respuesta a "¿por qué no LoRa / por qué no GSM?" es esta tabla con los costos cotizados (DOC-01).
- Los nodos con Wi-Fi dependen de la red del recinto. Si cae, el buffer local guarda las lecturas y las reenvía al reconectarse.
- Si se adopta LoRaWAN en el futuro, hay que agregar el endpoint de webhook del servidor de red y un nuevo ADR.
