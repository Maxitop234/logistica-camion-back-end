# Seguridad — EcoRuta Temuco (API)

La API recibe datos personales de los choferes (nombre, RUT, teléfono, licencia, ubicación durante la jornada), credenciales de usuarios y tokens de dispositivos, y está expuesta a internet.
Marco de referencia: ISO/IEC 27001/27002, OWASP Top 10 / ASVS / MASVS, TLS 1.2+, Ley 19.628, Ley 21.719, art. 5° del Código del Trabajo y Ley 21.459.

## 1. Activos y amenazas principales

| Activo | Amenaza | Control |
|---|---|---|
| Cuentas de administrador | Fuerza bruta, robo de token | Limitación de intentos, tokens con expiración, auditoría de accesos, HTTPS |
| Datos personales del chofer | Filtración de la base de datos o de los respaldos | Cifrado en la aplicación (`encrypted`), RUT como HMAC, RDS cifrado en reposo, mínimo privilegio |
| Ubicación del chofer | Vigilancia fuera de la jornada, uso indebido | Pings aceptados solo con ruta `en_curso`, retención limitada, acceso solo para `administrador` |
| Documentos de licencia | Acceso público a archivos | Disco privado, URL firmadas temporales, validación de tipo y tamaño |
| Token de un sensor | Suplantación del nodo, datos falsos | Un token por dispositivo con hash SHA-256, rotación, límite de peticiones, validación de rango físico |
| Disponibilidad de la API | Abuso de endpoints de alta frecuencia | Limitación de peticiones en `/gps/*` e `/iot/*`, timeouts, colas |

## 2. Controles implementados ✅

- **Autenticación:** tokens Bearer de Laravel Sanctum (`personal_access_tokens`), con `device_name` por sesión.
- **Autorización:** middleware `role:<rol>` (`EnsureUserHasRole`) y `active` (`EnsureUserIsActive`), que además revoca todos los tokens de una cuenta desactivada.
- **Limitación de intentos de login:** 5 fallos por minuto por combinación de correo e IP (`AuthController`).
- **Auditoría:** `audit_logs` registra `login`, `logout` y `failed_login` con IP, user agent y motivo, sin la contraseña.
- **Contraseñas:** hash bcrypt (cast `hashed`, `BCRYPT_ROUNDS=12`).
- **Datos personales:** `choferes.nombre_cifrado` y `telefono_cifrado` con el cast `encrypted`; el RUT se guarda solo como HMAC-SHA256 y se valida con el dígito verificador.
- **Bajas lógicas:** desactivar un chofer no borra su historial y revoca su acceso de inmediato.
- **CI:** Pint, Larastan y Pest en cada push y PR (`.github/workflows/tests.yml`); Dependabot para GitHub Actions.

## 3. Brechas conocidas ⚠️ (por corregir)

| # | Brecha | Riesgo | Tarea |
|---|---|---|---|
| S-01 | **Registro público de Fortify habilitado** (`POST /register`, `Features::registration()`). Cualquiera puede crear un usuario con el rol por defecto `chofer` y luego obtener un token en `/api/v1/auth/login` | Alto | BE-02 |
| S-02 | Rutas web de Fortify (login, reset, 2FA, passkeys) activas sin uso en la API | Medio | BE-02 |
| S-03 | Tokens Sanctum sin expiración (`sanctum.expiration = null`) | Medio | BE-02 |
| S-04 | `GET /api/user` devuelve el modelo `User` completo, fuera de `/v1` | Bajo | BE-02 |
| S-05 | El `motivo` de desactivación de camiones y choferes no queda auditado | Bajo | BE-02 |
| S-06 | Rotar `APP_KEY` vuelve ilegibles los datos cifrados y cambia los HMAC del RUT | Alto (operación) | Documentado; usar `APP_PREVIOUS_KEYS`. Para el RUT, re-hashear con un comando de mantenimiento |
| S-07 | Dependabot no revisa dependencias de Composer | Medio | BE-17 |
| S-08 | Sin política de retención de la traza GPS | Medio (Ley 21.719) | BE-18 |
| S-09 | Sin cabeceras de seguridad ni CORS restringido en producción | Medio | BE-16 |

## 4. Controles por implementar con cada módulo

- **IoT (BE-05, BE-06):** token aleatorio de 40 caracteres o más, guardado como `hash('sha256', token)` y comparado con `hash_equals`; header `X-IoT-Token`; `RateLimiter` de 12 peticiones por minuto por dispositivo; rechazo de distancias fuera del rango físico del sensor; token en `secrets.h`, excluido de Git.
- **GPS (BE-07, BE-08):** solo el rol `chofer` puede enviar posiciones, solo si tiene ruta `en_curso` y solo para su propio camión; 30 peticiones por minuto por usuario; validación de coordenadas dentro de la región.
- **Licencias (BE-12):** archivos en disco privado; MIME `application/pdf`, `image/jpeg` o `image/png`; tamaño máximo `LICENCIAS_MAX_KB`; nombre aleatorio; descarga solo con URL firmadas de 5 minutos; auditoría de cada verificación.
- **Rutas (BE-11):** un chofer solo opera sus propias rutas (policy `RutaPolicy`); `sync_uuid` único para evitar repeticiones.
- **Exportaciones (BE-14):** solo `administrador`; los reportes no incluyen RUT ni teléfonos.

## 5. Privacidad y marco legal

- **Minimización:** la app transmite la ubicación solo con una ruta en curso y deja de hacerlo al finalizarla. La API rechaza los pings fuera de ese estado.
- **Finalidad:** la ubicación y los tiempos sirven para la gestión operativa y los KPI de flota, no para evaluar disciplinariamente a una persona (art. 5° del Código del Trabajo).
- **Retención:** la traza detallada se conserva `GPS_RETENCION_DIAS` días; después se borra o se agrega por ruta (BE-18).
- **Transparencia:** los choferes reciben información previa sobre qué se recolecta, para qué y por cuánto tiempo (capacitación previa a la puesta en marcha).
- **Ley 21.719 (vigente desde el 01-12-2026):** documentar la finalidad, la base de licitud, los plazos y las medidas de seguridad; registro de accesos a datos personales (`audit_logs`).
- **Pruebas de seguridad:** solo sobre entornos propios y con autorización de la contraparte (Ley 21.459).

## 6. Gestión de secretos

- `.env` nunca se commitea (está en `.gitignore`); `.env.example` solo lleva claves vacías o valores de desarrollo.
- En staging y producción, los secretos (APP_KEY, credenciales de la base de datos, AWS) se guardan en el gestor de secretos del proveedor o como variables de entorno del host, no en la imagen Docker.
- Las credenciales Wi-Fi y el token del ESP32 van en `iot-esp32/include/secrets.h`, que está en `.gitignore`. Se entrega `secrets.example.h`.
- Doble factor en las cuentas de AWS y GitHub del equipo.

## 7. Checklist para cada PR

- [ ] Cada ruta nueva tiene `auth:sanctum` + `active` + `role:` (o el middleware IoT) y una prueba de acceso denegado (401/403).
- [ ] La validación usa un `FormRequest`; no se usa `$request->all()` en asignaciones masivas.
- [ ] No hay datos personales en logs, `audit_logs.detalles` ni mensajes de error.
- [ ] No hay secretos en el código, en los seeders ni en las pruebas.
- [ ] Los endpoints de alta frecuencia tienen limitación de peticiones.
- [ ] `docs/API.md` y este archivo están actualizados si cambió la superficie expuesta.

## 8. Reporte de vulnerabilidades

Avisar en privado al equipo (Maximiliano González o Nelson Ponce) por correo, nunca en un issue público. Se corrige antes de la siguiente liberación.
