# ADR-0012: Autenticación de cada sensor con su propio token

- **Estado:** Aceptada
- **Fecha:** 2026-10-28
- **Relacionado con:** BE-05, BE-06, IOT-01

## Contexto
Los nodos ESP32 envían lecturas sin intervención humana. Un nodo robado o un token filtrado no deben permitir falsear los demás puntos ni acceder a otras partes de la API.

## Decisión
- Cada dispositivo tiene un **token aleatorio propio** (40 caracteres o más) que se muestra **una sola vez** al registrarlo. La base de datos guarda solo `hash('sha256', token)`, y la comparación se hace con `hash_equals`.
- El nodo lo envía en el header `X-IoT-Token` por HTTPS. Un middleware `AutenticarDispositivo` resuelve el dispositivo y lo deja en la petición.
- El token solo da acceso a `POST /iot/telemetria`, y solo para el punto asociado a ese dispositivo.
- El administrador puede **rotar** el token (invalida el anterior) o desactivar el dispositivo.
- Límite de 12 peticiones por minuto por dispositivo.

## Alternativas consideradas
- **Tokens Sanctum ligados a un usuario técnico:** mezcla personas con máquinas y complica la revocación por nodo.
- **Certificados de cliente (mTLS):** más robusto, pero complejo de aprovisionar en el piloto.

## Consecuencias
- El firmware guarda el token en `secrets.h` (fuera de Git). Rotarlo implica volver a cargar el firmware o reconfigurar el nodo.
- Si se adopta LoRaWAN, la autenticación pasa al servidor de red y este ADR se reemplaza.
