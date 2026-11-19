# BE-15 · Cuentas y datos de demostración

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Nelson |
| Depende de | BE-04, BE-12, BE-16 |
| Origen | Demo final con datos reales y cuentas fáciles de recordar (una contraseña común) |

## Objetivo
El día de la demo final, staging tiene cuentas fáciles de recordar y datos reales listos: puntos importados, camiones y choferes reales (con su consentimiento), licencias verificadas, sensores instalados y varios días de rutas ejecutadas.

## Alcance
- Incluye: `DemoSeeder` separado de `DatabaseSeeder`, contraseña desde `DEMO_PASSWORD`, checklist previo a la demo.
- **No incluye generar rutas, posiciones ni lecturas falsas.** Los datos operativos de la demo son los acumulados en staging desde el Hito B (ver ROADMAP).

## Pasos sugeridos
1. `DemoSeeder` idempotente (`updateOrCreate`) que crea:
   - `administrador@ecoruta.test` (rol administrador);
   - `chofer1@ecoruta.test` y `chofer2@ecoruta.test` (los choferes reales o los integrantes del equipo, declarados como tales);
   - `sysadmin@ecoruta.test`.
   Todos con `DEMO_PASSWORD`; si la variable está vacía, el seeder se detiene con error.
2. Camiones: los dos camiones arrendados reales (patente, marca, modelo, capacidad, PBV), con datos de Somos Terranova.
3. Licencias: cargar los documentos reales con consentimiento, o licencias de los integrantes del equipo, y verificarlas desde el panel (no desde el seeder).
4. Quitar del seeder de desarrollo los nombres "Ejemplo" de la demo de staging (el `DatabaseSeeder` local puede mantenerlos).
5. Agregar a esta ficha un **checklist del día anterior**: login de cada cuenta, servidor y OSRM arriba, sensores reportando, al menos 5 días de rutas completadas, teléfonos cargados, plan B (punto de acceso móvil propio).

## Criterios de aceptación
- [ ] `php artisan db:seed --class=DemoSeeder` se puede ejecutar varias veces sin errores ni duplicados.
- [ ] Las cuatro cuentas inician sesión en staging con la misma contraseña.
- [ ] No existe ningún dato de ruta, GPS ni lectura creado por un seeder en staging (verificable por consulta: todas las rutas tienen `creada_por_user_id`).
- [ ] El checklist está completo y revisado por ambos integrantes.

## Archivos probables
`database/seeders/DemoSeeder.php`, `.env.example` (`DEMO_PASSWORD`), `tests/Feature/DemoSeederTest.php`.
