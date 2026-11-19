# ADR-0002: API REST en Laravel, versionada, con Sanctum y roles

- **Estado:** Aceptada
- **Fecha:** 2026-10-23
- **Relacionado con:** autenticación y control de acceso por rol; ADR-0012

## Contexto
El sistema tiene tres tipos de clientes: el panel web, la app móvil y los sensores ESP32. Todos necesitan un único punto de entrada seguro. El equipo tiene experiencia previa en Laravel.

## Decisión
- El back-end será una **API REST JSON** en Laravel (hoy 13.x, PHP 8.5 en Sail), sin vistas de servidor.
- Las rutas van bajo `/api/v1` y la serialización con `JsonResource`.
- La autenticación usará **tokens Bearer de Laravel Sanctum**, uno por dispositivo o sesión (`device_name`).
- La autorización por rol se hará con el middleware `role:<rol>` sobre la columna `users.role` (`administrador`, `chofer`, `sysadmin`), junto con `active` para bloquear cuentas desactivadas.
- Los sensores usan su propio esquema de token (ADR-0012), no Sanctum.

## Alternativas consideradas
- **Node.js (Express) o Django:** técnicamente viables, pero exigen ensamblar autenticación, roles y migraciones, o una curva de aprendizaje mayor.
- **Sesiones con cookies (Sanctum SPA):** no sirven para la app móvil ni para el ESP32.
- **Paquete de permisos (spatie/laravel-permission):** innecesario con tres roles fijos.

## Consecuencias
- El esqueleto incluye Fortify (registro, 2FA, passkeys, vistas web), que la API no usa y que hoy expone `POST /register`. Se desactiva en BE-02.
- Versionar desde `v1` permite cambiar contratos sin romper la app instalada en los teléfonos.
