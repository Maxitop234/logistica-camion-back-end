# BE-12 · Licencias de conducir: documentos, verificación y reglas

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Nelson |
| Depende de | BE-02 |
| Origen | La clase de licencia declarada no tiene respaldo verificable; ADR-0010 |

## Objetivo
La clase de licencia de cada chofer tiene respaldo documental verificado por un administrador, y el sistema impide asignar un camión o una ruta a un chofer sin una licencia válida para ese camión.

## Alcance
- Incluye: tabla `licencias_conducir`, columna `camiones.peso_bruto_vehicular_kg`, endpoints de licencia (§4.1 de `docs/API.md`), verificación auditada, reglas en la asignación de camión y en la generación de ruta, proceso diario de vencimiento.
- No incluye: integración en línea con el Registro Civil (no hay API pública identificada).

## Pasos sugeridos
1. Migraciones: `create_licencias_conducir_table` y `add_peso_bruto_vehicular_kg_to_camiones` (nullable; el seeder y el formulario la completan).
2. Servicio `ValidadorDeLicencias`:
   - `estaHabilitado(Chofer): bool` → licencia vigente, `verificada` y con `fecha_proximo_control` futura;
   - `cubreCamion(Licencia, Camion): bool` → si el PBV es mayor a 3.500 kg exige A4 o A5. **Confirmar la tabla de clases en el art. 12 de la Ley 18.290** y dejar la fuente en el código.
3. Endpoints `GET/POST /choferes/{id}/licencia` (multipart, disco privado `LICENCIAS_DISK`, nombre aleatorio, MIME y tamaño validados) y `POST /choferes/{id}/licencia/verificar`, auditado.
4. Documentos visibles con una URL firmada temporal (`URL::temporarySignedRoute`, 5 min) hacia un controlador que hace *stream* del archivo y verifica el rol.
5. Reglas:
   - `UpdateCamionRequest` (`chofer_actual_id`) responde 422 si el chofer no está habilitado o su clase no cubre el camión;
   - `GeneradorDeRutas` (BE-10) aplica la misma validación.
6. Al verificar, copiar la clase a `choferes.licencia_clase` (compatibilidad con el front actual).
7. Comando diario `licencias:marcar-vencidas`, que además genera la alerta `licencia_por_vencer` 30 días antes.

## Criterios de aceptación
- [ ] Un chofer sin licencia verificada no puede recibir un camión (422 con mensaje claro).
- [ ] Un chofer con licencia B no puede recibir un camión de PBV 8.000 kg; con A4 sí.
- [ ] La verificación registra quién, cuándo, el resultado y la observación, y queda en `audit_logs`.
- [ ] Los documentos no son accesibles sin la URL firmada vigente ni con el rol chofer.
- [ ] Una licencia con fecha de control pasada queda `vencida` tras ejecutar el comando.

## Pruebas
`LicenciaApiTest` (subida, verificación, permisos, URL firmada), `ValidadorDeLicenciasTest` (matriz clase × PBV) y `AsignacionCamionLicenciaTest`.

## Archivos probables
`app/Models/LicenciaConducir.php`, `app/Services/Licencias/ValidadorDeLicencias.php`, `app/Http/Controllers/Api/V1/LicenciaController.php`, `app/Http/Requests/Licencia/*`, `app/Console/Commands/MarcarLicenciasVencidas.php`, migraciones, `app/Http/Requests/Camion/UpdateCamionRequest.php`.
