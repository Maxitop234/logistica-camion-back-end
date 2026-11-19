# DOC-02 · Actualizar los diagramas a la arquitectura vigente

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Ambos |
| Depende de | Aceptar o rechazar los ADR propuestos (0005, 0007, 0009, 0010, 0013); resultados de DOC-01 y BE-17 |
| Origen | `docs/ARCHITECTURE.md` §7 (cambios respecto del diseño inicial) |

## Objetivo
Los diagramas del sistema describen exactamente lo que se va a demostrar y quedan versionados en `docs/diagramas/`, con su fuente editable (PlantUML, Mermaid o draw.io) y una exportación PNG o SVG.

## Lista de cambios
1. **Casos de uso:**
   - el actor externo deja de ser "Google Maps Platform" y pasa a ser "Motor de ruteo OSRM / OpenStreetMap";
   - se agregan los casos: gestionar kits de sensores, administrar GPS (teléfonos rastreadores), consultar consumo de comunicaciones, registrar y verificar la licencia del chofer;
   - revisar que `<<include>>` y `<<extend>>` vayan en la dirección correcta.
2. **Componentes, despliegue y arquitectura:** contenedor OSRM, nodo sensor con Wi-Fi, sin Google Maps (`docs/ARCHITECTURE.md`).
3. **Modelo de datos:** tablas y nombres vigentes (`docs/DATA_MODEL.md`), incluidas `sectores`, `dispositivos`, `dispositivos_moviles`, `licencias_conducir`, `consumo_comunicaciones` y `metas_kpi`, con cardinalidades correctas.
4. **KPI:** los seis KPI con meta del ADR-0009 y la distinción explícita entre métrica y KPI.
5. **Seguridad:** los controles vigentes de `docs/SECURITY.md` (cifrado, tokens por dispositivo, validación de licencias, retención GPS).
6. **Pruebas:** los resultados reales de BE-17, IOT-02 y la conectividad intermitente.
7. **Glosario** de los términos técnicos que se usan en los diagramas.

## Criterios de aceptación
- [ ] Ningún diagrama muestra Google Maps como componente vigente (solo como alternativa evaluada).
- [ ] Ambos integrantes revisaron cada diagrama contra el código y contra `docs/`.
- [ ] Los KPI documentados coinciden con los del tablero (FE-10).
