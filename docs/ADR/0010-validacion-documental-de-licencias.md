# ADR-0010: Validación documental de las licencias de conducir

- **Estado:** Propuesta
- **Fecha:** 2026-11-18
- **Relacionado con:** BE-12, FE-09; Ley 18.290 (Ley de Tránsito), clases de licencia

## Contexto
Hoy el administrador escribe la clase de licencia (p. ej. A4) y el sistema la acepta sin respaldo. Nada lo contrasta: alguien podría registrar una clase que no tiene y conducir un camión sin habilitación. No se identificó una API pública oficial para consultar licencias en línea.

## Decisión
1. Cada chofer tiene una **licencia registrada con respaldo documental**: clase, municipalidad, fecha del último control, fecha del próximo control (vencimiento), fotografía de ambas caras y, opcionalmente, el **certificado de hoja de vida del conductor** del Registro Civil.
2. La licencia queda `pendiente` hasta que un administrador la **verifica**: contrasta el documento y, si hay certificado, valida su código en el sitio oficial. La verificación registra quién, cuándo y con qué resultado (`audit_logs`).
3. **Reglas que el sistema hace cumplir:**
   - Solo un chofer con licencia `verificada` y no vencida puede tener un camión asignado y recibir una ruta.
   - La clase debe cubrir el camión: si el **peso bruto vehicular** es mayor a 3.500 kg, exige **A4 o A5** (A5 si es articulado). Confirmar las equivalencias en el art. 12 de la Ley 18.290 antes de cerrar BE-12.
   - Un proceso diario marca como `vencida` la licencia que pasó su fecha de control y genera una alerta 30 días antes.
4. Los documentos se guardan en un disco privado y se ven con URL firmadas temporales.

## Alternativas consideradas
- **Solo declarar la clase (situación actual):** no ofrece ningún respaldo verificable.
- **Integración con el Registro Nacional de Conductores:** sin API pública identificada; fuera de alcance.

## Consecuencias
- Agrega la tabla `licencias_conducir` y la columna `camiones.peso_bruto_vehicular_kg`.
- Los choferes existentes quedan `pendiente` hasta cargar sus documentos. El seeder de demo carga licencias verificadas de prueba.
- Los documentos son datos personales: cifrado, acceso restringido y retención definida (SECURITY.md).
