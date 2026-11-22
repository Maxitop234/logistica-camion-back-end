<?php

namespace App\Services\Puntos;

use Illuminate\Support\Str;

/**
 * Lee las planillas CSV de importación (BE-04): UTF-8 con encabezado, separador
 * coma o punto y coma (Excel en español guarda con `;`) y BOM opcional.
 *
 * Los encabezados se normalizan sin tildes, en minúsculas y con `_`
 * ("Días recolección" → `dias_recoleccion`). Cada fila conserva el número de
 * línea del archivo donde empieza, para informar los errores.
 */
class LectorDeCsv
{
    /**
     * @return array{encabezados: list<string>, filas: list<array{linea: int, valores: array<string, string>}>}
     *
     * @throws ArchivoDeImportacionInvalido
     */
    public function leer(string $ruta): array
    {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            throw new ArchivoDeImportacionInvalido("No se encontró el archivo {$ruta}.");
        }

        $contenido = (string) file_get_contents($ruta);
        if (str_starts_with($contenido, "\u{FEFF}")) {
            $contenido = substr($contenido, 3);
        }
        if (! mb_check_encoding($contenido, 'UTF-8')) {
            throw new ArchivoDeImportacionInvalido('El archivo no está en UTF-8: guárdalo como "CSV UTF-8" e inténtalo de nuevo.');
        }

        $primeraLinea = strtok($contenido, "\n") ?: '';
        $separador = substr_count($primeraLinea, ';') > substr_count($primeraLinea, ',') ? ';' : ',';

        $flujo = fopen('php://temp', 'r+');
        if ($flujo === false) {
            throw new ArchivoDeImportacionInvalido('No se pudo leer el archivo.');
        }
        fwrite($flujo, $contenido);
        rewind($flujo);

        $encabezados = null;
        $filas = [];
        $linea = 1;
        while (true) {
            $inicio = (int) ftell($flujo);
            $registro = fgetcsv($flujo, null, $separador, '"', '');
            if ($registro === false) {
                break;
            }
            $lineaDelRegistro = $linea;
            $linea += max(1, substr_count($contenido, "\n", $inicio, (int) ftell($flujo) - $inicio));

            $valores = array_map(static fn (?string $valor): string => trim((string) $valor), $registro);
            if (implode('', $valores) === '') {
                continue;
            }

            if ($encabezados === null) {
                $encabezados = array_map(self::normalizarEncabezado(...), $valores);

                continue;
            }

            $fila = [];
            foreach ($encabezados as $indice => $encabezado) {
                if ($encabezado !== '' && ! array_key_exists($encabezado, $fila)) {
                    $fila[$encabezado] = $valores[$indice] ?? '';
                }
            }
            $filas[] = ['linea' => $lineaDelRegistro, 'valores' => $fila];
        }
        fclose($flujo);

        if ($encabezados === null) {
            throw new ArchivoDeImportacionInvalido('El archivo está vacío.');
        }

        return [
            'encabezados' => array_values(array_filter($encabezados, static fn (string $encabezado): bool => $encabezado !== '')),
            'filas' => $filas,
        ];
    }

    /**
     * Primer encabezado presente entre los nombres aceptados para una columna.
     *
     * @param  list<string>  $encabezados
     * @param  list<string>  $alias
     */
    public static function columna(array $encabezados, array $alias): ?string
    {
        foreach ($alias as $nombre) {
            if (in_array($nombre, $encabezados, true)) {
                return $nombre;
            }
        }

        return null;
    }

    private static function normalizarEncabezado(string $encabezado): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii($encabezado))), '_');
    }
}
