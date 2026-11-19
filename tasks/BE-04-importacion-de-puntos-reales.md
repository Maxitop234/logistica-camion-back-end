# BE-04 · Importar los sectores y puntos reales desde CSV

| Campo | Valor |
|---|---|
| Estado | Pendiente |
| Prioridad | P0 |
| Responsable sugerido | Nelson |
| Depende de | BE-03; archivos CSV con los datos reales (los prepara el equipo) |
| Origen | Regla "sin datos simulados": el mapa y las rutas deben usar los puntos reales de Somos Terranova; ADR-0013 |

## Objetivo
Cargar en la base de datos todos los **sectores** (con sus días y frecuencia de recolección) y todos los **puntos de reciclaje** reales con coordenadas, mediante comandos repetibles y sin commitear datos de la empresa.

## Datos de entrada
El equipo digitaliza los datos de la empresa en dos archivos CSV (UTF-8, separador `,`, con encabezado). Se guardan en `storage/app/private/importaciones/`, carpeta **ignorada por Git**. Nunca se commitean.

**`sectores.csv`**

| Columna | Obligatoria | Formato | Ejemplo |
|---|---|---|---|
| `nombre` | sí | texto | `Sector A` |
| `dias_recoleccion` | sí | días separados por `\|`, como número ISO (1 = lunes) o abreviatura | `2\|4` o `mar\|jue` |
| `periodicidad_semanal` | sí | entero 1–7 | `2` |
| `puntos_esperados` | no | entero | `25` |

**`puntos.csv`**

| Columna | Obligatoria | Formato | Ejemplo |
|---|---|---|---|
| `sector` | sí | nombre exacto de un sector de `sectores.csv` | `Sector A` |
| `nombre` | sí | texto | `Punto plaza central` |
| `direccion` | sí* | calle, número y localidad | `Av. Ejemplo 123, Temuco` |
| `coordenadas` | sí* | `latitud, longitud` decimal **o** grados, minutos y segundos | `-38.7623611, -72.7618889` o `38°45'44.5"S 72°45'42.8"W` |
| `tipo_punto` | no | `condominio`, `punto_calle`, `empresa`, `municipio` | `punto_calle` |
| `tipo_material` | no | `plastico`, `vidrio`, `carton`, `mixto` | `mixto` |
| `nota` | no | texto libre | `Junto al paradero` |

\* Al menos una de las dos. Con `coordenadas` no se geocodifica. Solo con `direccion`, se geocodifica y el punto queda para revisión.

## Alcance
- Incluye:
  - comando `sectores:importar {csv} [--dry-run]`;
  - comando `puntos:importar {csv} [--geocodificar] [--dry-run]`;
  - conversión de grados, minutos y segundos a decimal;
  - geocodificación con caché;
  - reporte de conciliación por sector.
- No incluye: leer `.xlsx` (requeriría aprobar `phpoffice/phpspreadsheet`).

## Pasos sugeridos
1. `sectores:importar`:
   - hace *upsert* por nombre normalizado;
   - guarda `dias_recoleccion`, `periodicidad_semanal` y `puntos_esperados`;
   - si la cantidad de días no coincide con la frecuencia, avisa y **usa los días** (son los que necesita el generador de rutas).
2. `puntos:importar`:
   - `codigo_externo` = hash estable de `sector + nombre + direccion` (reimportar no duplica);
   - coordenadas: primero `coordenadas` (decimal o grados, minutos y segundos); si falta y se usa `--geocodificar`, consulta Nominatim (OpenStreetMap) con `"{direccion}, Temuco, Araucanía, Chile"`, **a 1 petición por segundo**, con User-Agent identificable y caché en `storage/app/private/geocodificacion.json`;
   - valida que la coordenada esté en la zona de Temuco–Padre Las Casas;
   - `fuente_ubicacion` = `coordenadas`, `geocodificada` o `manual`; `requiere_revision = true` si se geocodificó o si quedó fuera de la zona;
   - una fila con errores no detiene la importación: se informa con su número de línea.
3. **Conciliación:** al terminar, imprime por sector los puntos creados, actualizados, por revisar y omitidos, y la diferencia con `puntos_esperados`.
4. Revisar en FE-05 los puntos con `requiere_revision` hasta dejarlos en cero.
5. Anotar en esta ficha la fecha de la importación y los totales (sin datos de la empresa).

## Criterios de aceptación
- [ ] Todos los sectores existen con sus días de recolección confirmados por la empresa.
- [ ] Cada sector tiene sus puntos importados, y cualquier diferencia con `puntos_esperados` está explicada.
- [ ] 0 puntos activos sin coordenadas y 0 con `requiere_revision` antes del Hito A (o la lista pendiente anotada aquí).
- [ ] Reimportar los mismos archivos no crea duplicados.
- [ ] `git status` limpio: ningún archivo de datos de la empresa en el repositorio.

## Pruebas
- `ImportarSectoresTest` con un CSV de 3 sectores inventados en `tests/Fixtures/`, incluido un caso con días y frecuencia inconsistentes.
- `ImportarPuntosTest` con filas de coordenadas decimales, en grados, minutos y segundos, y solo con dirección; `Http::fake` para Nominatim.
- `CoordenadasTest` unitaria: `38°45'44.5"S 72°45'42.8"W` → `-38.7623611, -72.7618889`.

## Archivos probables
`app/Console/Commands/{ImportarSectores,ImportarPuntos}.php`, `app/Services/Puntos/{ExtractorDeCoordenadas,Geocodificador}.php`, `tests/Feature/Importar*Test.php`, `tests/Unit/CoordenadasTest.php`, `tests/Fixtures/*.csv`.
