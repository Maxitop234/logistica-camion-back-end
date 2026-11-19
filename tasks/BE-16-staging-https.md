# BE-16 · Servidor de staging accesible por HTTPS

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Maximiliano |
| Depende de | BE-01 |
| Origen | Despliegue objetivo (AWS EC2 + RDS + Route 53, `docs/ARCHITECTURE.md` §5) |

## Objetivo
La API corre en un servidor público con dominio y HTTPS, al que llegan los teléfonos de los choferes (4G) y los nodos ESP32 desde la calle.

## Contexto
Sin un servidor público no hay datos reales: los teléfonos y los sensores no alcanzan un `localhost`. Android bloquea HTTP en claro por defecto, y el ESP32 debe validar el certificado.

## Alcance
- Incluye: imagen Docker de producción (Nginx + PHP-FPM, sin Sail), `docker-compose.prod.yml` (app, worker, scheduler, OSRM y PostgreSQL/PostGIS, o RDS), dominio, TLS, variables de entorno, despliegue reproducible, respaldo diario, CORS para el origen del panel web, cabeceras de seguridad (S-09).
- Opcional: GitHub Actions que construye la imagen y despliega al etiquetar.

## Pasos sugeridos
1. Elegir el host: **AWS EC2 `t3.medium`** como el despliegue objetivo (estimación inicial: USD 55–70 al mes con RDS) u otra opción equivalente; documentar en DOC-01 el costo real y la razón.
2. `docker/production/Dockerfile` (PHP 8.5-FPM con `pdo_pgsql`, opcache y Composer sin dev) + `nginx.conf`.
3. `docker-compose.prod.yml` con `restart: unless-stopped` y healthcheck en `/up`.
4. TLS con Let's Encrypt (Caddy, Traefik o certbot) o AWS ACM + ALB.
5. Variables de entorno en el host (nunca en la imagen): `APP_ENV=production`, `APP_DEBUG=false` y `APP_URL` con https.
6. Respaldo diario con `pg_dump` a S3 o almacenamiento externo, más una **prueba de restauración** documentada (RTO 4 h, RPO 5 min).
7. Documentar el despliegue paso a paso en el README (sección nueva) o en `docker/production/README.md`.

## Criterios de aceptación
- [ ] `https://<dominio>/up` responde 200 con un certificado válido.
- [ ] Un teléfono con datos móviles inicia sesión en la app contra staging.
- [ ] `curl -X POST https://<dominio>/api/v1/iot/telemetria` sin token responde 401 (la ruta es alcanzable desde internet).
- [ ] `APP_DEBUG=false`: un error no muestra trazas.
- [ ] Se restauró un respaldo en un entorno aparte y se anotó cuánto tardó.

## Archivos probables
`docker/production/{Dockerfile,nginx.conf,README.md}`, `docker-compose.prod.yml`, `.github/workflows/deploy.yml` (opcional), `config/cors.php`.
