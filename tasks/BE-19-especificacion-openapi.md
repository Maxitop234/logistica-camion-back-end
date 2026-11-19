# BE-19 · Especificación OpenAPI de la API

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P2 |
| Responsable sugerido | — |
| Depende de | — (más útil después de BE-13) |
| Origen | Gestión de la configuración: especificación de la API versionada |

## Objetivo
Una especificación OpenAPI 3 generada desde el código, publicada en `/docs/api` solo fuera de producción, como complemento de `docs/API.md`.

## Pasos sugeridos
1. Evaluar un generador que lea FormRequests y Resources (p. ej. `dedoc/scramble`); requiere aprobar la dependencia.
2. Proteger la ruta de documentación (solo `local` y `staging`).
3. Agregar un paso de CI que falle si la especificación no se puede generar.

## Criterios de aceptación
- [ ] La especificación incluye todos los endpoints `/api/v1` con sus esquemas de entrada y salida.
- [ ] `docs/API.md` enlaza la especificación y sigue siendo la referencia de las reglas de negocio.
