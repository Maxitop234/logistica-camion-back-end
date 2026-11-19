# BE-02 · Endurecer la seguridad: registro público, tokens y auditoría de bajas

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Nelson |
| Depende de | — |
| Origen | `docs/SECURITY.md` §3 (S-01 a S-05) |

## Objetivo
Cerrar las brechas conocidas antes de exponer la API a internet (BE-16).

## Contexto
- **S-01:** `config/fortify.php` habilita `Features::registration()`, así que `POST /register` existe (verificado con `route:list`). Un usuario creado así obtiene el rol por defecto `chofer`.
- **S-02:** Fortify publica rutas web (login, reset, 2FA, passkeys) que la API no usa.
- **S-03:** `config/sanctum.php` tiene `'expiration' => null`.
- **S-04:** `routes/api.php` expone `GET /api/user` fuera de `/v1`.
- **S-05:** el front envía `{motivo}` al desactivar camiones y choferes, pero el back-end lo ignora.

## Alcance
- Incluye: desactivar el registro y las vistas de Fortify, expiración de tokens, eliminar `/api/user`, auditar las bajas con motivo.
- No incluye: desinstalar Fortify (evaluarlo; si se desinstala, que sea con una prueba que lo respalde).

## Pasos sugeridos
1. En `config/fortify.php`, quitar `Features::registration()` (y las features que la API no use) y poner `'views' => false`. Revisar `FortifyServiceProvider`.
2. `config/sanctum.php`: `'expiration' => env('SANCTUM_EXPIRATION') !== null ? (int) env('SANCTUM_EXPIRATION') : null` (ya está en `.env.example`) y programar `sanctum:prune-expired --hours=24` en `routes/console.php`.
3. Eliminar la ruta `GET /api/user`.
4. Crear `DesactivarCamionRequest` y `DesactivarChoferRequest` con `motivo` opcional (string, ≤ 500). En `destroy`, registrar `AuditLog::registrar('camion_desactivado' | 'chofer_desactivado', $request->user(), $request, ['camion_id' => …, 'motivo' => …])`, sin datos personales.
5. Documentar los cambios en `docs/SECURITY.md` (marcar S-01 a S-05 como resueltas) y en `docs/API.md`.

## Criterios de aceptación
- [ ] `POST /register` responde 404 o 405 y `route:list` ya no muestra `register`.
- [ ] `GET /api/user` responde 404.
- [ ] Un token más antiguo que `SANCTUM_EXPIRATION` recibe 401.
- [ ] Desactivar un camión o un chofer con motivo deja un `audit_logs` con el motivo y el id del recurso.
- [ ] Las pruebas existentes de autenticación y CRUD siguen pasando.

## Pruebas
`SecurityHardeningTest` (registro, `/api/user`, token expirado con `travel()`), y ampliación de `CamionApiTest` y `ChoferApiTest` para la auditoría de bajas.

## Archivos probables
`config/fortify.php`, `config/sanctum.php`, `app/Providers/FortifyServiceProvider.php`, `routes/api.php`, `routes/console.php`, `app/Http/Requests/{Camion,Chofer}/Desactivar*Request.php`, controladores de camiones y choferes, `tests/Feature/*`.
