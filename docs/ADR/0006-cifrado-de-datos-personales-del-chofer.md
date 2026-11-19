# ADR-0006: Cifrado de los datos personales del chofer

- **Estado:** Aceptada
- **Fecha:** 2026-11-14
- **Relacionado con:** Ley 19.628 y Ley 21.719; `app/Models/Chofer.php`

## Contexto
El sistema guarda el nombre, el RUT, el teléfono y la ubicación de trabajadores. Una filtración de la base de datos o de un respaldo no debe exponerlos en texto plano. Al mismo tiempo, hay que impedir RUT duplicados.

## Decisión
- `nombre_cifrado` y `telefono_cifrado` usan el cast `encrypted` de Laravel (AES-256-CBC con `APP_KEY`).
- El RUT no se guarda: se guarda `rut_hash = HMAC-SHA256(rut_normalizado, APP_KEY)`, único. Se valida con el dígito verificador antes de calcular el hash.
- Las agregaciones de KPI agrupan por `choferes.id` y descifran los nombres solo al serializar.
- Con BE-12, el número de licencia también se cifra.

## Alternativas consideradas
- **Cifrado de columna en PostgreSQL (pgcrypto):** las claves quedarían en las consultas SQL.
- **Solo cifrado en reposo de RDS:** no protege ante un volcado lógico ni ante un acceso indebido a la base de datos.

## Consecuencias
- No se puede buscar ni ordenar por nombre en SQL; se filtra en memoria (volumen bajo: pocos choferes).
- **Rotar `APP_KEY` es una operación delicada:** hay que usar `APP_PREVIOUS_KEYS` para descifrar y recalcular los `rut_hash` con un comando de mantenimiento.
