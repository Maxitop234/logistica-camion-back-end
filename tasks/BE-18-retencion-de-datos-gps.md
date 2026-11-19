# BE-18 · Retención y privacidad de la traza GPS (Ley 21.719)

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P1 |
| Responsable sugerido | Maximiliano |
| Depende de | BE-07 |
| Origen | Ley 19.628, Ley 21.719 (vigente desde el 01-12-2026), art. 5° del Código del Trabajo; `docs/SECURITY.md` S-08 |

## Objetivo
La traza GPS detallada se conserva solo el tiempo necesario y la política queda documentada para la demo y para la empresa.

## Pasos sugeridos
1. Antes de borrar, guardar en `rutas` los agregados que los KPI necesiten (p. ej. `distancia_real_km` calculada desde la traza).
2. Comando diario `gps:aplicar-retencion`: elimina las filas de `ubicaciones_gps` más antiguas que `GPS_RETENCION_DIAS` y registra cuántas eliminó (en el log, sin coordenadas).
3. Documentar en `docs/SECURITY.md` §5: finalidad, base de licitud, plazo, quién accede y cómo se informa a los choferes.
4. Redactar el texto informativo para los choferes (una página) que se entrega en la capacitación.

## Criterios de aceptación
- [ ] Con `GPS_RETENCION_DIAS=90`, una posición de hace 91 días se elimina y una de hace 89 no.
- [ ] Los KPI de rutas antiguas no cambian después de aplicar la retención.
- [ ] La política y el texto para choferes quedan en el repositorio.

## Archivos probables
`app/Console/Commands/AplicarRetencionGps.php`, `routes/console.php`, migración (`rutas.distancia_real_km`), `docs/SECURITY.md`.
