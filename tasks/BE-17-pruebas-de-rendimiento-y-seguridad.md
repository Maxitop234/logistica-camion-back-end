# BE-17 · Pruebas de rendimiento (k6) y de seguridad (ZAP, audit)

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P1 |
| Responsable sugerido | Nelson |
| Depende de | BE-16 (y los endpoints de BE-07, BE-10 y BE-13) |
| Origen | Plan de pruebas (k6, OWASP ZAP, composer audit); SLA P95 ≤ 800 ms |

## Objetivo
Evidencia medida de que se cumplen los SLA bajo la carga nominal del piloto y de que no hay vulnerabilidades críticas ni altas abiertas.

## Pasos sugeridos
1. `tests/k6/carga_nominal.js`: 5 choferes con ping cada 10 s, 3 administradores consultando `/gps/flota` cada 12 s y el tablero cada minuto, y una generación de ruta; duración de 10 min. Umbrales: `http_req_duration p(95) < 800` y `http_req_failed < 1 %`.
2. `tests/k6/estres.js`: rampa hasta 10 veces la carga nominal; registrar el punto de degradación y la recuperación.
3. OWASP ZAP *baseline* contra staging (con autorización, Ley 21.459); clasificar los hallazgos.
4. `composer audit` en CI; agregar `package-ecosystem: composer` a `.github/dependabot.yml` (S-07).
5. Resumir los resultados en `tests/k6/RESULTADOS.md` (fecha, entorno, P95, errores, hallazgos y decisiones) para la documentación final (DOC-02).

## Criterios de aceptación
- [ ] P95 < 800 ms y errores < 1 % en carga nominal contra staging.
- [ ] 0 hallazgos ZAP de severidad alta abiertos; los medios tienen una decisión documentada.
- [ ] `composer audit` sin vulnerabilidades conocidas o con una justificación escrita.
- [ ] Resultados guardados y referenciados en DOC-02.

## Archivos probables
`tests/k6/*.js`, `tests/k6/RESULTADOS.md`, `.github/dependabot.yml`, `.github/workflows/tests.yml`.
