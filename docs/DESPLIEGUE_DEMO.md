# Despliegue de la demo (gratis)

Neon (PostgreSQL + PostGIS) · Render (API en Docker) · Netlify (panel web) · APK firmado. Todo se configura por variables de entorno: para migrar a AWS (RDS, ECS/App Runner, S3 + CloudFront) solo cambian los valores. Ningún secreto vive en el repositorio.

## Variables del servicio en Render

| Variable | Valor |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_KEY` | `base64:…` (se genera una vez con `artisan key:generate --show`; cifra datos de choferes: no se cambia después) |
| `APP_URL` | URL https del servicio en Render |
| `APP_DEMO_MODE` | `true` |
| `LOG_CHANNEL` | `stderr` |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | datos de Neon (host directo, sin `-pooler`) |
| `DB_SSLMODE` | `require` |
| `QUEUE_CONNECTION` / `SESSION_DRIVER` / `CACHE_STORE` | `sync` / `database` / `database` |
| `CORS_ALLOWED_ORIGINS` | URL del panel en Netlify (varias, separadas por coma) |
| `OSRM_BASE_URL` | `https://router.project-osrm.org` (servidor público, solo demo; si falla, la API usa el respaldo local) |

`PORT` lo inyecta Render. Las `DEMO_*` van solo en `.env.neon` (siembra local), no en Render. El health check es `/up`. No hay cron ni worker: la API no define tareas programadas ni jobs en cola.

## Sembrar la base desde el equipo local

Usa un archivo aparte e ignorado por git (`.env.neon`), nunca el `.env` de desarrollo. Revisa el host antes de ejecutar:

```bash
vendor/bin/sail artisan migrate --force --env=neon
vendor/bin/sail artisan db:seed --class=DemoSeeder --force --env=neon
vendor/bin/sail artisan ecoruta:generar-historial --meses=3 --env=neon
```

`DemoSeeder` también carga los 12 sectores reales (`SectorSeeder`) y asigna a ellos los puntos de prueba. Es repetible: en una base ya sembrada actualiza los datos sin duplicarlos, así que los mismos tres comandos sirven para poner al día una demo desplegada antes de BE-03.

Nunca `migrate:fresh` contra la base remota. Con `APP_ENV=production` Laravel además bloquea los comandos destructivos.

## Panel web (Netlify)

```bash
flutter build web --release --dart-define=API_BASE_URL=https://<servicio>.onrender.com --dart-define=APP_DEMO_MODE=true
```

`web/_headers` y `web/_redirects` viajan dentro de `build/web` (caché `no-cache` para `main.dart.js`, `flutter_bootstrap.js` y `version.json`, y fallback de rutas a `index.html`). Publicación:

- **Manual:** arrastrar la carpeta `build/web` a https://app.netlify.com/drop (o a "Deploys" del sitio).
- **Una línea (CLI):** `netlify deploy --prod --dir=build/web` (requiere `npm i -g netlify-cli` y `netlify login`; la primera vez `netlify link`).

Las subidas manuales no ejecutan build: lo que esté en `build/web` es lo que se publica.

## APK

Keystore, `android/key.properties` y comando de compilación: `docs/DESPLIEGUE.md` del repositorio del frontend. Cada versión nueva sube el número tras `+` en `version:` de `pubspec.yaml` (`1.0.0+2`, `1.0.0+3`…); Android rechaza instalar un build menor o igual sobre uno ya instalado.

## Actualizar la demo

El auto-deploy de Render está **desactivado**: un `git push` no despliega nada. Para actualizar:

1. Render → **Manual Deploy → Deploy latest commit**.
2. Después, si el cambio trae migraciones: `vendor/bin/sail artisan migrate --force --env=neon` (y el seeder correspondiente si cambió el esquema de los datos de demo).
3. Si cambió el frontend: recompilar `flutter build web` con los mismos `--dart-define` y volver a subir `build/web` a Netlify.

Un código nuevo sobre una base sin migrar rompe los endpoints (p. ej. `GET /puntos` con 500 al faltar `sector_id`), por eso el orden es primero el deploy y enseguida las migraciones.

## Límites del plan gratis (verificados en noviembre de 2026; revisar antes de la entrega)

- **Render:** 750 horas de instancia al mes; se duerme tras 15 min sin tráfico y despierta en ~1 min; disco efímero (no se guardan archivos; las licencias subidas se pierden al reiniciar).
- **Neon:** 100 CU-horas de cómputo y 1 GB de almacenamiento por proyecto; el cómputo se suspende a los 5 min sin uso.
- **Netlify:** plan por créditos (300 al mes; cada deploy a producción consume créditos); al agotarlos el sitio se suspende hasta fin de mes.
- Antes de una presentación, abrir el panel un minuto antes para despertar API y base.
