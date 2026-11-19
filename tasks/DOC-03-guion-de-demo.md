# DOC-03 · Guion de la demo final y ensayos

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Ambos |
| Depende de | BE-15 (cuentas y datos), DOC-01, DOC-02 |
| Origen | Regla "sin datos simulados"; demostrar todas las funcionalidades comprometidas |

## Objetivo
Una demo cronometrada, repartida entre ambos integrantes, que muestra **todas** las funcionalidades con datos reales y está ensayada varias veces.

## Contenido del guion
Si el guion incluye datos de la empresa, se guarda **fuera del repositorio**.

1. **Introducción (≈ 5 min):**
   - problema de Somos Terranova, con las cifras reales de su operación que la empresa autorice mostrar;
   - objetivos y arquitectura (un diagrama);
   - tecnologías elegidas y por qué (ADR);
   - medidas de seguridad.
2. **Demo** (cada bloque con su responsable y su tiempo):
   1. Login como administrador (cuenta de BE-15).
   2. Mapa OSM con los puntos reales y su estado, y la flota en vivo.
   3. Kits de sensores: un sensor real reportando, su batería y su señal, y la foto de la instalación.
   4. Generación de la ruta del día con OSRM.
   5. App del chofer: ruta, inicio, registro en modo avión y sincronización.
   6. Administración de GPS y Comunicaciones (consumo medido).
   7. Choferes: licencia con documento verificado e intento de asignar un camión a una licencia no válida (rechazado).
   8. Tablero de KPI contra meta, alertas y exportación PDF.
3. **Plan B** por bloque: sin internet, sensor sin batería, teléfono descargado.
4. **Preguntas técnicas probables**, con respuesta corta y referencia a DOC-01:
   - sensor, batería y alcance;
   - Wi-Fi, LoRa o red celular; gateway y permisos;
   - costos y servidor;
   - KPI frente a métrica;
   - licencias;
   - por qué OSM, por qué Flutter y Laravel.

   Preparar también qué parte del código mostrar si la piden.
5. **Glosario** de términos precisos, evitando expresiones vagas.

## Criterios de aceptación
- [ ] Al menos 3 ensayos completos cronometrados, con su duración anotada.
- [ ] Cada integrante presenta aproximadamente la mitad y sabe responder sobre la parte del otro.
- [ ] El checklist del día anterior de BE-15 está completo.
- [ ] Cada funcionalidad comprometida aparece en la demo o tiene una explicación de por qué no.
