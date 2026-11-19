# BE-01 · Habilitar PostgreSQL + PostGIS en Sail y en CI

| Campo | Valor |
|---|---|
| Estado | Pendiente |
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
- [ ] `vendor/bin/sail artisan tinker --execute 'echo DB::selectOne("select postgis_version() v")->v;'` imprime una versión.
- [ ] `vendor/bin/sail artisan test` pasa completo contra PostgreSQL.
- [ ] El workflow de CI pasa en una rama con PostgreSQL + PostGIS (adjuntar el enlace a la ejecución en el PR).
- [ ] El README sigue siendo correcto para un clon nuevo.

## Pruebas
Prueba Pest `PostgisTest` que verifica que la extensión existe (se omite con `skip` si el driver no es pgsql).

## Archivos probables
`compose.yaml`, `database/migrations/*_enable_postgis_extension.php`, `.github/workflows/tests.yml`, `tests/Feature/PostgisTest.php`.
