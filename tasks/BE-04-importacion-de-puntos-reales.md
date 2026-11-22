# BE-04 · Importar los sectores y puntos reales desde CSV

| Campo | Valor |
|---|---|
| Estado | En progreso |
| Prioridad | P0 |
| Responsable sugerido | Nelson |
| Depende de | BE-03; archivos CSV con los datos reales (los prepara el equipo) |
| Origen | Regla "sin datos simulados": el mapa y las rutas deben usar los puntos reales de Somos Terranova; ADR-0013 |

## Objetivo
Cargar en la base de datos todos los **sectores** (con sus días y frecuencia de recolección) y todos los **puntos de reciclaje** reales con coordenadas, mediante comandos repetibles y sin commitear datos de la empresa.

## Datos de entrada
Archivos CSV en UTF-8, con encabezado y separador `,` o `;` (Excel en español guarda con `;`). Se guardan en `storage/app/private/importaciones/`, carpeta **ignorada por Git**. Nunca se commitean.

Los encabezados se leen sin tildes ni mayúsculas. Los comandos aceptan dos formatos:

- **la planilla del equipo** (`puntos_reciclaje_temuco.csv`): una fila por punto con su sector, `lat`, `lng`, `dias` y `frecuencia_semanal`. Sirve tal cual para los dos comandos;
- **dos archivos separados**, `sectores.csv` y `puntos.csv`, con las columnas de abajo.

**Sectores** (`sectores:importar`)

| Columna (alias) | Obligatoria | Formato | Ejemplo |
|---|---|---|---|
| `nombre` (`sector`) | sí | texto; se reconoce aunque cambien la puntuación, las tildes o un paréntesis descriptivo | `Sector A` |
| `dias_recoleccion` (`dias`) | sí | números ISO (1 = lunes), nombres o abreviaturas, separados por `\|`, comas o espacios | `2\|4`, `mar\|jue` o `martes, jueves` |
| `periodicidad_semanal` (`frecuencia_semanal`) | no | entero 1–7; si falta, la cantidad de días | `2` |
| `puntos_esperados` | no | entero | `25` |

En la planilla de puntos el sector se repite en cada fila: se toma la primera y se avisa si otra trae días distintos.

**Puntos** (`puntos:importar`)

| Columna (alias) | Obligatoria | Formato | Ejemplo |
|---|---|---|---|
| `sector` | sí | nombre de un sector existente | `Sector A` |
| `nombre` | sí | texto; si trae coordenadas (filas "pin" de la planilla), el nombre se toma de la dirección | `Punto plaza central` |
| `direccion` | sí* | calle, número y localidad | `Av. Ejemplo 123, Temuco` |
| `coordenadas` | sí* | `latitud, longitud` decimal **o** grados, minutos y segundos | `-38.7623611, -72.7618889` o `38°45'44.5"S 72°45'42.8"W` |
| `latitud`/`longitud` (`lat`/`lng`) | sí* | en vez de `coordenadas`, una columna para cada valor | `-38.7623611` |
| `tipo_punto` | no | `condominio` o `calle` (`punto_calle` se lee como `calle`). Si falta, `condominio` cuando la referencia o el nombre dicen "condominio" o "edificio"; si no, `calle` | `calle` |
| `referencia` | no | texto; se agrega a la nota si no está ya en el nombre | `Colegio` |
| `tipo_material` | no | `plastico`, `vidrio`, `carton`, `mixto` | `mixto` |
| `nota` (`obs`) | no | texto libre | `Junto al paradero` |

\* La dirección o las coordenadas. Con coordenadas no se geocodifica. Solo con `direccion` y `--geocodificar`, se geocodifica y el punto queda para revisión.

## Alcance
- Incluye:
  - comando `sectores:importar {archivo} [--dry-run]`;
  - comando `puntos:importar {archivo} [--geocodificar] [--dry-run]`;
  - conversión de grados, minutos y segundos a decimal;
  - geocodificación con caché;
  - reporte de conciliación por sector.
- No incluye: leer `.xlsx` (requeriría aprobar `phpoffice/phpspreadsheet`).

## Cómo quedó implementado
1. `sectores:importar` (`ImportadorDeSectores`):
   - hace *upsert* por nombre normalizado (`Sector::claveDeNombre`). Un sector existente, p. ej. de `SectorSeeder`, conserva su nombre;
   - guarda `dias_recoleccion` y `periodicidad_semanal`, y `puntos_esperados` solo si viene;
   - si la cantidad de días no coincide con la frecuencia, avisa y **usa los días** (son los que necesita el generador de rutas).
2. `puntos:importar` (`ImportadorDePuntos`):
   - `codigo_externo` = SHA-256 de `sector + nombre + direccion` normalizados, así reimportar no duplica. En la planilla real esa combinación se repite con **ubicaciones distintas** (varios pines "Labranza", "Los Fundadores" o "Pin colocado" en un mismo sector). Por eso cada repetición agrega su número de aparición (`#2`, `#3`). Si se repite con la **misma ubicación** (a menos de 10 m), es un duplicado de la planilla y se omite;
   - coordenadas: primero `coordenadas`, luego `latitud`/`longitud` y, en las filas "pin", el nombre. Si faltan y se usa `--geocodificar`, se consulta Nominatim (OpenStreetMap) con `"{direccion}, Temuco, Araucanía, Chile"`, acotado a la zona de operación (`viewbox` + `bounded`). La consulta respeta el límite global de **1 petición por segundo** y usa el User-Agent de `NOMINATIM_USER_AGENT`. Los aciertos quedan 30 días en la caché de Laravel, que se reutiliza en vez del archivo `geocodificacion.json`;
   - una coordenada **fuera de la zona** de Temuco–Padre Las Casas no se importa: se informa con su línea para corregirla en el archivo. La API tampoco acepta puntos fuera de la zona, y un punto así no debe entrar en una ruta;
   - `fuente_ubicacion` = `coordenadas` o `geocodificada`; `requiere_revision = true` si se geocodificó;
   - al reimportar se actualizan los datos del archivo. No se tocan el estado de llenado, el sensor ni `activo`, y se conserva la ubicación de un punto que un administrador movió en el panel (`fuente_ubicacion = manual`);
   - una fila con errores no detiene la importación: se informa con su número de línea. También se avisa cuando un mismo lugar aparece en dos sectores (queda un punto en cada uno).
3. **Conciliación:** al terminar se imprime, por sector, cuántos puntos trae el archivo y cuántos se crearon, se actualizaron, quedaron sin cambios o se omitieron, además de los activos en la base, los esperados, la diferencia y los que quedan por revisar.
4. Revisar en FE-05 los puntos con `requiere_revision` hasta dejarlos en cero.
5. Anotar en esta ficha la fecha de la importación y los totales (sin datos de la empresa).

```bash
vendor/bin/sail artisan sectores:importar storage/app/private/importaciones/puntos_reciclaje_temuco.csv --dry-run
```

```bash
vendor/bin/sail artisan puntos:importar storage/app/private/importaciones/puntos_reciclaje_temuco.csv --dry-run
```

Sin `--dry-run`, los mismos comandos guardan los cambios.

## Criterios de aceptación
- [ ] Todos los sectores existen con sus días de recolección confirmados por la empresa.
- [ ] Cada sector tiene sus puntos importados, y cualquier diferencia con `puntos_esperados` está explicada.
- [x] 0 puntos activos sin coordenadas y 0 con `requiere_revision` antes del Hito A (o la lista pendiente anotada aquí).
- [x] Reimportar los mismos archivos no crea duplicados.
- [x] `git status` limpio: ningún archivo de datos de la empresa en el repositorio.

## Avance (2026-11-21)

Comandos, servicios y pruebas listos. Verificado con la planilla real en una base de prueba aislada (PostgreSQL de Sail, migraciones al día y `SectorSeeder`):

- `sectores:importar`: reconoce los 12 sectores de la planilla como los de `SectorSeeder`, sin cambios. Avisa la frecuencia distinta de los días en "S.Martin, Av.Alemania, Thiers, P.Dreves" (1 vez con 2 días) y en "Amanecer y Amanecer Alto" (3 veces con 2 días).
- `puntos:importar`: 504 filas → **503 puntos creados**, 1 omitido (duplicado que la propia planilla marca en el mismo sector). 0 sin coordenadas, 0 fuera de la zona y 0 por revisar. 19 filas "pin" toman el nombre de su dirección. Tarda alrededor de 1 s.
- Reimportar: 0 creados, 503 sin cambios.
- 3 lugares aparecen en dos sectores (la planilla lo anota con "También en sector N"): queda un punto en cada sector.

**Diferencia con `puntos_esperados` (cierre de mayo, 478 en total):** hay 25 puntos más. La columna `agregado` de la planilla registra 42 puntos sumados después de mayo, lo que cuadra exacto en 7 sectores. Quedan por confirmar con la empresa:

| Sector | Importados | Esperados | Agregados después de mayo | Sin explicar |
|---|---|---|---|---|
| Avenida Alemania, aragon, Torre Molino | 17 | 25 | 2 | −10 |
| Pedro de Valdivia | 70 | 62 | 10 | −2 |
| Poniente | 51 | 51 | 1 | −1 |
| Las Quilas, centro | 40 | 31 | 10 | −1 |
| Langdon, Turingia, V.Los Ríos | 43 | 40 | 4 | −1 |
| Avenida Estadio, Javierra carrera | 24 | 24 | 1 | −1 |

**Pendiente para cerrar la tarea:**
1. Confirmar con Somos Terranova los días de los 12 sectores (en especial los 2 con frecuencia distinta) y las diferencias de la tabla (¿puntos dados de baja después de mayo?).
2. Ejecutar la importación en los entornos del equipo y en staging (BE-16). Revisar la conciliación y anotar aquí la fecha y los totales.

## Pruebas
- `ImportarSectoresTest` con un CSV de 3 sectores inventados en `tests/Fixtures/importaciones/`, incluido un caso con días y frecuencia inconsistentes, la planilla en formato del equipo y el reconocimiento de los nombres reales con otra puntuación.
- `ImportarPuntosTest` con filas de coordenadas decimales, en grados, minutos y segundos, y solo con dirección (`Http::fake` para Nominatim). Cubre fuera de la zona, sector inexistente, reimportación, ubicación movida a mano, conciliación, `--dry-run` y la planilla con pines, nombres repetidos y duplicados.
- `CoordenadasTest` unitaria: `38°45'44.5"S 72°45'42.8"W` → `-38.7623611, -72.7618889`.

## Archivos
`app/Console/Commands/{ImportarSectores,ImportarPuntos}Command.php`, `app/Services/Puntos/{ImportadorDeSectores,ImportadorDePuntos,ExtractorDeCoordenadas,LectorDeCsv,ReporteDeImportacion,ArchivoDeImportacionInvalido}.php`, `app/Services/NominatimGeocoderService.php` (`buscar`), `app/Models/Sector.php` (`diasDesdeTexto`, `claveDeNombre`), `app/Concerns/InformaResultadoDeImportacion.php`, `tests/Feature/Importar*Test.php`, `tests/Unit/CoordenadasTest.php`, `tests/Fixtures/importaciones/*.csv`.
