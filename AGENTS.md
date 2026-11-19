# AGENTS.md — EcoRuta Temuco · API back-end (Laravel)

Instrucciones para agentes de IA (Claude Code, OpenCode, Antigravity, etc.) que trabajen en este repositorio.
La sección del proyecto (esta) manda sobre las guías genéricas de Laravel Boost que siguen más abajo.

## 1. Contexto en 30 segundos

- **Qué es:** API REST (`/api/v1`) de EcoRuta Temuco para Somos Terranova. Genera rutas de recolección según el llenado real de los puntos de reciclaje, rastrea la flota por GPS del teléfono del chofer y calcula KPI con metas.
- **Clientes:** app Flutter (web para `administrador`, móvil para `chofer`) en el repo hermano `../logistica_camion_front_end`, y nodos sensores ESP32 (firmware en `iot-esp32/`, pendiente).
- **Stack:** Laravel 13 · PHP 8.5 (Sail) · PostgreSQL (+ PostGIS, pendiente BE-01) · Sanctum (tokens Bearer) · Pest · Pint · Larastan.
- **Plazo:** entrega final el 11-12-2026, con una demo del sistema funcionando. **Regla de oro: nada de datos simulados en la demo.**

## 2. Fuentes de verdad (léelas antes de cambiar código)

| Necesitas saber… | Lee |
|---|---|
| Qué hacer y en qué orden | `tasks/README.md` (índice general de BE, FE, IOT y DOC) |
| Contrato de cada endpoint | `docs/API.md` |
| Tablas, columnas y estados | `docs/DATA_MODEL.md` |
| Componentes y flujos | `docs/ARCHITECTURE.md` |
| Decisiones ya tomadas | `docs/ADR/` (solo las `Aceptada` son obligatorias) |
| Controles de seguridad | `docs/SECURITY.md` |

Si el código contradice la documentación, **el código manda** y la documentación se corrige en el mismo cambio.

## 3. Flujo de trabajo para una tarea

1. Elige **una** tarea de `tasks/` cuyas dependencias estén en estado `Hecha`. No mezcles tareas en un mismo cambio.
2. Lee la tarea completa, los ADR que cita y las secciones de `docs/` relacionadas.
3. Cambia el estado de la tarea a `En progreso`, también en la tabla de `tasks/README.md`.
4. Implementa con pruebas Pest (feature tests con factories). Cada criterio de aceptación debe quedar cubierto por una prueba o por una verificación manual descrita en la tarea.
5. Actualiza en el mismo cambio `docs/API.md`, `docs/DATA_MODEL.md` y `.env.example` si tocaste endpoints, esquema o variables. **Mantener `docs/` y `tasks/` al día es parte de cada tarea y cuenta como solicitud explícita de documentación.**
6. Si tomaste una decisión de arquitectura nueva, agrega un ADR (`docs/ADR/README.md` explica cómo).
7. Ejecuta `vendor/bin/sail bin pint --dirty --format agent`, `vendor/bin/sail composer types:check` y las pruebas afectadas.
8. Marca la tarea como `Hecha` solo si se cumplen todos los criterios de aceptación. Si algo quedó pendiente, déjala `En progreso` y anota lo que falta.

## 4. Reglas del dominio (no negociables)

- **Estados de llenado:** `vacio` (0–10 %), `bajo` (11–40 %), `medio` (41–70 %), `alto` (71–89 %), `lleno` (90–100 %). Con estado **vigente**, solo `alto` y `lleno` entran en una ruta y los demás cuentan como excluidos (KPI-06). Un punto **sin estado vigente** (la gran mayoría: cientos de puntos y solo dos sensores en el piloto) entra por el calendario de su sector ([ADR-0013](docs/ADR/0013-estado-de-puntos-sin-sensor.md)). Nunca trates "desconocido" como `vacio`.
- **Porcentaje de llenado:** `clamp((altura_contenedor_cm − distancia_cm) / (altura_contenedor_cm − distancia_sensor_lleno_cm) × 100, 0, 100)`, redondeado a entero, con ambos valores configurados por dispositivo (`distancia_sensor_lleno_cm = 0` equivale a la fórmula simple `(altura − distancia) / altura`).
- **Idempotencia offline:** cada recolección trae un `sync_uuid` (UUID v4 generado en el teléfono). Si ya existe, se responde `200` sin duplicar.
- **GPS proporcional (Ley 21.719 y art. 5° del Código del Trabajo):** solo se aceptan pings de un chofer con ruta `en_curso`. Nunca se rastrea fuera de la jornada.
- **Datos personales:** nombre y teléfono del chofer se guardan con el cast `encrypted`; el RUT solo como HMAC (`Chofer::hashRut`). Nunca registres datos personales en logs ni en `audit_logs.detalles`.
- **Sin datos simulados:** ningún endpoint devuelve datos inventados ni de ejemplo. Si no hay datos, se responde vacío o con el error correspondiente. Los seeders de demostración usan datos reales o claramente marcados como de prueba.
- **Roles:** `administrador` (panel), `chofer` (app), `sysadmin` (reservado). Protege cada ruta con `auth:sanctum`, `active` y `role:<rol>`.
- **Camiones arrendados:** no hay OBD2 ni GPS instalado en el camión ([ADR-0004](docs/ADR/0004-gps-desde-el-telefono-del-chofer.md)).

## 5. Convenciones de código

- Dominio en **español** (tablas, columnas, modelos, mensajes de error, nombres de métodos de dominio). El código de framework y las palabras reservadas siguen en inglés.
- Tablas en plural y snake_case (`camiones`, `ruta_puntos`). Modelos en singular con `#[Table('...')]` cuando el plural no es regular.
- Endpoints bajo `Route::prefix('v1')` en `routes/api.php`, con controladores en `app/Http/Controllers/Api/V1/`.
- Validación en `FormRequest` (`app/Http/Requests/<Recurso>/`) y salida con `JsonResource` (`{"data": …}`). Errores con `{"message": "…"}` y el código HTTP correcto (401, 403, 404, 409, 422, 429).
- Lógica de negocio no trivial en `app/Services/` (p. ej. `Rutas/GeneradorDeRutas`, `Iot/ProcesadorDeTelemetria`, `Kpi/CalculadoraDeKpi`). Los controladores quedan delgados.
- Fechas en UTC con ISO 8601 en la API (`2026-11-18T14:05:00Z`). Usa `timestampTz` en las columnas nuevas.
- Coordenadas WGS84 (SRID 4326), latitud y longitud con 7 decimales.
- Commits en español con Conventional Commits, siguiendo `git log`: `feat(api): …`, `fix(auth): …`, `docs(tareas): …`, `test(rutas): …`. Sin coautorías de IA.

## 6. Comandos

Todo se ejecuta a través de Sail (ver guías de Boost abajo):

```bash
vendor/bin/sail up -d
vendor/bin/sail artisan migrate:fresh --seed
vendor/bin/sail artisan test --compact --filter=Camion
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail composer types:check
```

## 7. Límites

- **No edites** el repo hermano `../logistica_camion_front_end`. Puedes leerlo para verificar qué consume el cliente (`lib/features/*/data/*_repository.dart`).
- No agregues dependencias de Composer sin que la tarea lo indique o sin aprobación.
- No modifiques `.env`, no commitees secretos y no imprimas el valor de `APP_KEY`.
- No inventes datos técnicos ni de costos (alcance del sensor, consumo de batería, precios). Si una tarea los pide, deja la fuente o márcalos como `POR CONFIRMAR`.
- Fuera de alcance: facturación, integración contable o de RR. HH., predicción con IA, mantención mecánica de vehículos.

---

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `vendor/bin/sail npm run build`, `vendor/bin/sail npm run dev`, or `vendor/bin/sail composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `vendor/bin/sail artisan route:list`). Use `vendor/bin/sail artisan list` to discover available commands and `vendor/bin/sail artisan [command] --help` to check parameters.
- Inspect routes with `vendor/bin/sail artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `vendor/bin/sail artisan config:show app.name`, `vendor/bin/sail artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `vendor/bin/sail artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `vendor/bin/sail artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail npm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `vendor/bin/sail artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `vendor/bin/sail artisan list` and check their parameters with `vendor/bin/sail artisan [command] --help`.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `vendor/bin/sail artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev` or `vendor/bin/sail composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/sail bin pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test --format agent`, simply run `vendor/bin/sail bin pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `vendor/bin/sail artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `vendor/bin/sail artisan make:test --pest SomeFeatureTest` instead of `vendor/bin/sail artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `vendor/bin/sail artisan test --compact` or filter: `vendor/bin/sail artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
