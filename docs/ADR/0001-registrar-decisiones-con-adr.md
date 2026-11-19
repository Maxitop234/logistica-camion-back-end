# ADR-0001: Registrar las decisiones de arquitectura con ADR

- **Estado:** Aceptada
- **Fecha:** 2026-11-18
- **Decisores:** Maximiliano González, Nelson Ponce

## Contexto
El proyecto se desarrolla en dos repositorios y con apoyo de agentes de IA (Claude Code, OpenCode). Las decisiones quedaron repartidas entre documentos de diseño externos, un plan de tareas y conversaciones, y el código ya se aparta del diseño inicial en varios puntos (mapas, KPI, conectividad del sensor). Hay que poder explicar por qué se eligió cada tecnología.

## Decisión
Registraremos cada decisión de arquitectura en `docs/ADR/` con el formato de la plantilla del `README.md` de la carpeta. Las decisiones que afectan a ambos repos se escriben en el repo donde vive la mayor parte de la implementación, y el otro las enlaza.

## Alternativas consideradas
- **Solo documentos externos:** no están versionados con el código y los agentes no los leen.
- **Wiki de GitHub:** queda fuera del repositorio que leen los agentes.

## Consecuencias
- Los agentes tienen una fuente única de decisiones vigentes.
- Las respuestas a "¿por qué eligieron X?" quedan escritas.
- Cada cambio de rumbo cuesta un archivo corto más.
