# BE-01 · Habilitar PostgreSQL + PostGIS en Sail y en CI

| Campo | Valor |
|---|---|
| Estado | Hecha (2026-11-18) |
| Prioridad | P0 |
| Responsable sugerido | Maximiliano |
| Depende de | — |
| Origen | ADR-0003 |

## Objetivo
El entorno local y el de CI usan PostgreSQL con PostGIS, y las pruebas corren contra PostgreSQL, no contra SQLite.

## Contexto
- `compose.yaml` usa `postgres:18-alpine`, que no trae PostGIS.
- `.env.example` ya apunta a `pgsql` (host `pgsql`, base `ecoruta`).
- El workflow `.github/workflows/tests.yml` ejecuta `composer setup` (copia `.env.example` y migra) y luego `composer ci:check`, **sin servicio de base de datos**. Con el nuevo `.env.example`, CI falla hasta completar esta tarea.
- `phpunit.xml` fija `DB_DATABASE=testing`; Sail crea esa base con `create-testing-database.sql`.

## Alcance
- Incluye: imagen con PostGIS, migración que crea la extensión, servicio PostgreSQL en CI, README actualizado.
- No incluye: columnas geográficas de dominio (BE-03).

## Pasos sugeridos
1. En `compose.yaml`, cambiar la imagen del servicio `pgsql` a una etiqueta de `postgis/postgis` para PostgreSQL 18 (verificar la etiqueta en Docker Hub, p. ej. `18-3.6`). Mantener el volumen y el healthcheck.
2. Crear la migración `enable_postgis_extension`: `DB::statement('CREATE EXTENSION IF NOT EXISTS postgis')` solo si `DB::getDriverName() === 'pgsql'`; `down()` no elimina la extensión.
3. En `tests.yml`, agregar `services.postgres` con la misma imagen (`POSTGRES_DB=testing`, `POSTGRES_USER`, `POSTGRES_PASSWORD`, `--health-cmd pg_isready`) y las variables `DB_HOST=127.0.0.1`, `DB_DATABASE=testing`, `DB_USERNAME` y `DB_PASSWORD` en el job.
4. `vendor/bin/sail down` → `up -d` → `artisan migrate:fresh --seed` y ejecutar toda la suite.

## Criterios de aceptación
- [x] PostGIS responde una versión (`postgis 3.6.4`) y la migración la crea en una base que no la tenía.
- [x] La suite completa pasa contra PostgreSQL + PostGIS (80 pruebas).
- [x] El job de CI pasa: verificado reproduciéndolo localmente (`composer ci:check`, código de salida 0) con el mismo servicio y variables; la ejecución en GitHub Actions queda en la pestaña Actions del commit.
- [x] El README explica cómo levantar el entorno en un clon nuevo y cómo recrear el contenedor si ya existía.

## Pruebas
Prueba Pest `PostgisTest` que verifica que la extensión existe (se omite con `skip` si el driver no es pgsql).

## Resultado

- **Imagen:** `postgis/postgis:18-3.6-alpine` en `compose.yaml` y en CI. Es Alpine, como la `postgres:18-alpine` anterior, así que un volumen existente sigue sirviendo. Solo se publica para amd64.
- **Migración:** `2026_11_18_210000_enable_postgis_extension` (no hace nada fuera de pgsql; `down()` no borra la extensión).
- **CI:** servicio `postgres` con PostGIS, variables `DB_*` a nivel del job (tienen prioridad sobre el `.env` copiado de `.env.example`) y extensiones `pdo_pgsql` y `pgsql` en `setup-php`.
- **Arreglos necesarios para que CI pase:**
  - PHPStan ya fallaba en `main` por `UserResource::toArray()` (claves numéricas por `mergeWhen`). Ahora arma el arreglo con claves de texto, sin cambiar la respuesta, cubierto por dos pruebas nuevas de `me`.
  - `.env.example` usa `APP_FALLBACK_LOCALE=en`: no hay archivos en `lang/es`, y con `es` los mensajes de validación salían como claves (`validation.required`).
- **Verificación local:** Pint ✅ · PHPStan 0 errores ✅ · Pest 80/80 ✅, contra un contenedor PostGIS desechable.

## Archivos probables
`compose.yaml`, `database/migrations/*_enable_postgis_extension.php`, `.github/workflows/tests.yml`, `tests/Feature/PostgisTest.php`.
