<?php

namespace App\Services\Puntos;

use App\Enums\TipoPunto;
use App\Http\Requests\Punto\StorePuntoRequest;
use App\Models\Punto;
use App\Models\Sector;
use App\Services\NominatimGeocoderService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Crea o actualiza los puntos de reciclaje reales desde un CSV (BE-04).
 *
 * Reglas:
 * - El sector se reconoce por su nombre normalizado y debe existir
 *   (`sectores:importar` o `SectorSeeder`).
 * - Coordenadas desde `coordenadas` (decimal o grados, minutos y segundos), desde
 *   `latitud`/`longitud` (o `lat`/`lng`) o, en las filas tipo "pin" de la planilla,
 *   desde el nombre. Sin coordenadas, y con `geocodificar`, se buscan en Nominatim
 *   y el punto queda para revisión. Fuera de la zona de operación la fila no se
 *   importa: se informa para corregirla en el archivo.
 * - `codigo_externo` = SHA-256 de sector + nombre + dirección normalizados. Si la
 *   misma combinación se repite en el sector con otra ubicación (p. ej. varios
 *   pines "Labranza"), cada repetición agrega su número de aparición (`#2`, `#3`).
 *   Si se repite con la misma ubicación es un duplicado de la planilla y se omite.
 * - Reimportar actualiza los datos del archivo y no duplica. No toca el estado
 *   de llenado, el sensor ni `activo`, y conserva la ubicación de un punto que un
 *   administrador movió a mano (`fuente_ubicacion = manual`).
 */
class ImportadorDePuntos
{
    /**
     * Distancia bajo la cual dos filas iguales del mismo sector son el mismo punto.
     */
    private const DISTANCIA_DUPLICADO_M = 10.0;

    public function __construct(
        private LectorDeCsv $lector,
        private ExtractorDeCoordenadas $extractor,
        private NominatimGeocoderService $geocodificador,
    ) {}

    /**
     * @throws ArchivoDeImportacionInvalido
     */
    public function importar(string $ruta, bool $geocodificar = false, bool $simular = false): ReporteDeImportacion
    {
        $csv = $this->lector->leer($ruta);
        $columnas = $this->columnas($csv['encabezados']);
        $reporte = new ReporteDeImportacion($simular);

        DB::beginTransaction();
        try {
            $sectores = Sector::query()->orderBy('id')->get()
                ->keyBy(static fn (Sector $sector): string => Sector::claveDeNombre($sector->nombre));

            /** @var array<int, array{en_archivo: int, creados: int, actualizados: int, sin_cambios: int, omitidos: int}> $porSector */
            $porSector = [];
            /** @var array<string, list<array{linea: int, latitud: float, longitud: float}>> $vistos */
            $vistos = [];
            /** @var array<string, array<int, int>> $lugares Líneas por sector de cada ubicación */
            $lugares = [];

            foreach ($csv['filas'] as ['linea' => $linea, 'valores' => $fila]) {
                $valor = static fn (string $campo): string => $columnas[$campo] !== null ? ($fila[$columnas[$campo]] ?? '') : '';

                $textoSector = $valor('sector');
                $sector = $sectores->get(Sector::claveDeNombre($textoSector));
                if (! $sector instanceof Sector) {
                    $reporte->omitidos++;
                    $reporte->error($linea, $textoSector === ''
                        ? 'Falta el sector.'
                        : "El sector «{$textoSector}» no existe: impórtalo primero con `sectores:importar`.");

                    continue;
                }

                $contador = $porSector[$sector->id] ?? ['en_archivo' => 0, 'creados' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'omitidos' => 0];
                $contador['en_archivo']++;
                $resultado = $this->importarFila($linea, $valor, $sector, $geocodificar, $vistos, $reporte);
                $contador[$resultado['resultado']]++;
                $porSector[$sector->id] = $contador;

                if ($resultado['ubicacion'] !== null) {
                    $lugar = sprintf('%.5f,%.5f', $resultado['ubicacion']['latitud'], $resultado['ubicacion']['longitud']);
                    $lugares[$lugar][$sector->id] ??= $linea;
                }
            }

            $this->avisarLugaresEnVariosSectores($lugares, $sectores, $reporte);
            $reporte->filas = $this->conciliacion($sectores, $porSector);
        } catch (Throwable $error) {
            DB::rollBack();

            throw $error;
        }

        $simular ? DB::rollBack() : DB::commit();

        return $reporte;
    }

    /**
     * @param  callable(string): string  $valor
     * @param  array<string, list<array{linea: int, latitud: float, longitud: float}>>  $vistos
     * @return array{resultado: 'creados'|'actualizados'|'sin_cambios'|'omitidos', ubicacion: array{latitud: float, longitud: float}|null}
     */
    private function importarFila(int $linea, callable $valor, Sector $sector, bool $geocodificar, array &$vistos, ReporteDeImportacion $reporte): array
    {
        $omitida = ['resultado' => 'omitidos', 'ubicacion' => null];
        $nombreOriginal = $valor('nombre');
        $direccionOriginal = $valor('direccion');

        $ubicacion = $this->coordenadasDeLaFila($linea, $valor, $nombreOriginal, $reporte);
        if ($ubicacion === false) {
            $reporte->omitidos++;

            return $omitida;
        }

        $fuente = Punto::FUENTE_COORDENADAS;
        if ($ubicacion === null) {
            $ubicacion = $this->geocodificar($linea, $direccionOriginal, $geocodificar, $reporte);
            if ($ubicacion === null) {
                $reporte->omitidos++;

                return $omitida;
            }
            $fuente = Punto::FUENTE_GEOCODIFICADA;
        }

        if (! $this->dentroDeLaZona($ubicacion['latitud'], $ubicacion['longitud'])) {
            $reporte->omitidos++;
            $reporte->error($linea, sprintf(
                'La ubicación (%.7f, %.7f) está fuera de la zona de operación (%s): corrígela en el archivo.',
                $ubicacion['latitud'],
                $ubicacion['longitud'],
                config('ecoruta.zona.nombre'),
            ));

            return $omitida;
        }

        $nombre = $this->nombreDelPunto($nombreOriginal, $direccionOriginal);
        if ($nombre === '') {
            $reporte->omitidos++;
            $reporte->error($linea, 'Falta el nombre del punto.');

            return $omitida;
        }

        $base = implode('|', [Sector::claveDeNombre($sector->nombre), $this->normalizar($nombreOriginal), $this->normalizar($direccionOriginal)]);
        foreach ($vistos[$base] ?? [] as $anterior) {
            if ($this->distanciaEnMetros($anterior['latitud'], $anterior['longitud'], $ubicacion['latitud'], $ubicacion['longitud']) <= self::DISTANCIA_DUPLICADO_M) {
                $reporte->omitidos++;
                $reporte->aviso($linea, "Repite el punto de la línea {$anterior['linea']} (mismo sector, nombre, dirección y ubicación): se omite.");

                return $omitida;
            }
        }
        $aparicion = count($vistos[$base] ?? []) + 1;
        $vistos[$base][] = ['linea' => $linea, ...$ubicacion];
        $codigoExterno = hash('sha256', $aparicion > 1 ? "{$base}#{$aparicion}" : $base);

        $datos = [
            'nombre' => $nombre,
            'nombre_automatico' => false,
            'direccion' => mb_substr($direccionOriginal !== '' ? $direccionOriginal : sprintf('%.7f, %.7f', $ubicacion['latitud'], $ubicacion['longitud']), 0, 255),
            'tipo_punto' => $this->tipoDePunto($linea, $valor('tipo_punto'), $valor('referencia').' '.$nombreOriginal, $reporte),
            'sector_id' => $sector->id,
            'tipo_material' => $this->tipoDeMaterial($linea, $valor('tipo_material'), $reporte),
            'nota' => $this->nota($valor('referencia'), $valor('nota'), $nombre),
        ];
        $datosDeUbicacion = [
            'latitud' => number_format($ubicacion['latitud'], 7, '.', ''),
            'longitud' => number_format($ubicacion['longitud'], 7, '.', ''),
            'fuente_ubicacion' => $fuente,
            'requiere_revision' => $fuente === Punto::FUENTE_GEOCODIFICADA,
        ];

        $punto = Punto::query()->withoutEagerLoads()->where('codigo_externo', $codigoExterno)->first();
        if ($punto === null) {
            Punto::create([...$datos, ...$datosDeUbicacion, 'codigo_externo' => $codigoExterno, 'activo' => true]);
            $reporte->creados++;

            return ['resultado' => 'creados', 'ubicacion' => $ubicacion];
        }

        $punto->fill($datos);
        if ($punto->fuente_ubicacion === Punto::FUENTE_MANUAL) {
            $reporte->aviso($linea, "El punto «{$nombre}» fue movido a mano en el panel: se conserva su ubicación.");
        } else {
            $punto->fill($datosDeUbicacion);
        }

        if (! $punto->isDirty()) {
            $reporte->sinCambios++;

            return ['resultado' => 'sin_cambios', 'ubicacion' => $ubicacion];
        }

        $punto->save();
        $reporte->actualizados++;

        return ['resultado' => 'actualizados', 'ubicacion' => $ubicacion];
    }

    /**
     * @param  callable(string): string  $valor
     * @return array{latitud: float, longitud: float}|false|null `false` si la fila trae coordenadas inválidas; `null` si no trae
     */
    private function coordenadasDeLaFila(int $linea, callable $valor, string $nombre, ReporteDeImportacion $reporte): array|false|null
    {
        $texto = $valor('coordenadas');
        if ($texto !== '') {
            $coordenadas = $this->extractor->extraer($texto);
        } elseif ($valor('latitud') !== '' || $valor('longitud') !== '') {
            $texto = trim($valor('latitud').', '.$valor('longitud'), ', ');
            $coordenadas = $this->extractor->extraerDeColumnas($valor('latitud'), $valor('longitud'));
        } else {
            return $this->extractor->extraer($nombre);
        }

        if ($coordenadas === null) {
            $reporte->error($linea, "Las coordenadas «{$texto}» no son válidas (usa «-38.7623611, -72.7618889» o «38°45'44.5\"S 72°45'42.8\"W»).");

            return false;
        }

        return $coordenadas;
    }

    /**
     * @return array{latitud: float, longitud: float}|null
     */
    private function geocodificar(int $linea, string $direccion, bool $geocodificar, ReporteDeImportacion $reporte): ?array
    {
        if ($direccion === '') {
            $reporte->error($linea, 'La fila no trae coordenadas ni dirección.');

            return null;
        }
        if (! $geocodificar) {
            $reporte->error($linea, 'La fila no trae coordenadas: agrégalas o vuelve a importar con --geocodificar.');

            return null;
        }

        $resultado = $this->geocodificador->buscar($this->consultaDeGeocodificacion($direccion));
        if ($resultado === null) {
            $reporte->error($linea, "No se encontró la dirección «{$direccion}» en OpenStreetMap: agrega sus coordenadas.");

            return null;
        }

        return ['latitud' => $resultado['latitud'], 'longitud' => $resultado['longitud']];
    }

    private function consultaDeGeocodificacion(string $direccion): string
    {
        $minusculas = Str::lower(Str::ascii($direccion));
        $consulta = $direccion;
        if (! str_contains($minusculas, 'temuco') && ! str_contains($minusculas, 'padre las casas')) {
            $consulta .= ', Temuco';
        }
        if (! str_contains($minusculas, 'araucania')) {
            $consulta .= ', Araucanía';
        }

        return $consulta.', Chile';
    }

    private function dentroDeLaZona(float $latitud, float $longitud): bool
    {
        return $latitud >= (float) config('ecoruta.zona.latitud_min')
            && $latitud <= (float) config('ecoruta.zona.latitud_max')
            && $longitud >= (float) config('ecoruta.zona.longitud_min')
            && $longitud <= (float) config('ecoruta.zona.longitud_max');
    }

    /**
     * El nombre de la fila o, si la planilla puso ahí las coordenadas (filas
     * "pin"), la primera parte de la dirección.
     */
    private function nombreDelPunto(string $nombre, string $direccion): string
    {
        if ($nombre === '' || $this->extractor->extraer($nombre) !== null) {
            $nombre = trim(explode(',', $direccion)[0]);
        }

        return mb_substr($nombre, 0, 255);
    }

    private function tipoDePunto(int $linea, string $tipo, string $referencia, ReporteDeImportacion $reporte): string
    {
        $tipo = Str::lower(trim($tipo));
        if ($tipo === 'punto_calle') {
            $tipo = TipoPunto::Calle->value;
        }
        if (in_array($tipo, TipoPunto::valores(), true)) {
            return $tipo;
        }
        if ($tipo !== '') {
            $reporte->aviso($linea, "El tipo de punto «{$tipo}» no es válido (condominio o calle): se deduce de la referencia.");
        }

        return preg_match('/\b(condominio|edificio)\b/', Str::lower(Str::ascii($referencia))) === 1
            ? TipoPunto::Condominio->value
            : TipoPunto::Calle->value;
    }

    private function tipoDeMaterial(int $linea, string $material, ReporteDeImportacion $reporte): ?string
    {
        $material = Str::lower(Str::ascii(trim($material)));
        if ($material === '') {
            return null;
        }
        if (in_array($material, StorePuntoRequest::TIPOS_MATERIAL, true)) {
            return $material;
        }
        $reporte->aviso($linea, "El tipo de material «{$material}» no es válido (".implode(', ', StorePuntoRequest::TIPOS_MATERIAL).'): queda sin material.');

        return null;
    }

    private function nota(string $referencia, string $observacion, string $nombre): ?string
    {
        $partes = [];
        if ($referencia !== '' && ! str_contains(Str::lower($nombre), Str::lower($referencia))) {
            $partes[] = "Referencia: {$referencia}";
        }
        if ($observacion !== '') {
            $partes[] = $observacion;
        }

        return $partes === [] ? null : mb_substr(implode('. ', $partes), 0, 1000);
    }

    private function normalizar(string $texto): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($texto))));
    }

    private function distanciaEnMetros(float $latitud1, float $longitud1, float $latitud2, float $longitud2): float
    {
        $metrosPorGrado = 111_320.0;
        $dx = ($longitud2 - $longitud1) * $metrosPorGrado * cos(deg2rad(($latitud1 + $latitud2) / 2));
        $dy = ($latitud2 - $latitud1) * $metrosPorGrado;

        return sqrt(($dx * $dx) + ($dy * $dy));
    }

    /**
     * @param  array<string, array<int, int>>  $lugares
     * @param  Collection<string, Sector>  $sectores
     */
    private function avisarLugaresEnVariosSectores(array $lugares, Collection $sectores, ReporteDeImportacion $reporte): void
    {
        $nombres = $sectores->mapWithKeys(static fn (Sector $sector): array => [$sector->id => $sector->nombre]);
        foreach ($lugares as $lineasPorSector) {
            if (count($lineasPorSector) < 2) {
                continue;
            }
            $detalle = [];
            foreach ($lineasPorSector as $sectorId => $linea) {
                $detalle[] = "«{$nombres->get($sectorId)}» (línea {$linea})";
            }
            $reporte->aviso(min($lineasPorSector), 'El mismo lugar está en los sectores '.implode(' y ', $detalle).': queda un punto en cada sector.');
        }
    }

    /**
     * Conciliación por sector: lo que trajo el archivo frente a lo que queda en la
     * base y a los puntos que el sector debería tener.
     *
     * @param  Collection<string, Sector>  $sectores
     * @param  array<int, array{en_archivo: int, creados: int, actualizados: int, sin_cambios: int, omitidos: int}>  $porSector
     * @return list<array<string, int|string|null>>
     */
    private function conciliacion(Collection $sectores, array $porSector): array
    {
        $activos = Punto::query()->withoutEagerLoads()->where('activo', true)
            ->selectRaw('sector_id, count(*) as total, sum(case when requiere_revision then 1 else 0 end) as por_revisar')
            ->groupBy('sector_id')
            ->get()
            ->keyBy('sector_id');

        $filas = [];
        foreach ($sectores as $sector) {
            if (! $sector->activo && ! isset($porSector[$sector->id])) {
                continue;
            }
            $contador = $porSector[$sector->id] ?? ['en_archivo' => 0, 'creados' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'omitidos' => 0];
            $enBase = (int) ($activos->get($sector->id)?->getAttribute('total') ?? 0);

            $filas[] = [
                'sector' => $sector->nombre,
                ...$contador,
                'activos_en_base' => $enBase,
                'puntos_esperados' => $sector->puntos_esperados,
                'diferencia' => $sector->puntos_esperados === null ? null : $enBase - $sector->puntos_esperados,
                'por_revisar' => (int) ($activos->get($sector->id)?->getAttribute('por_revisar') ?? 0),
            ];
        }

        return $filas;
    }

    /**
     * @param  list<string>  $encabezados
     * @return array<string, string|null>
     */
    private function columnas(array $encabezados): array
    {
        $columnas = [
            'sector' => LectorDeCsv::columna($encabezados, ['sector', 'nombre_sector']),
            'nombre' => LectorDeCsv::columna($encabezados, ['nombre', 'nombre_punto']),
            'direccion' => LectorDeCsv::columna($encabezados, ['direccion']),
            'coordenadas' => LectorDeCsv::columna($encabezados, ['coordenadas']),
            'latitud' => LectorDeCsv::columna($encabezados, ['latitud', 'lat']),
            'longitud' => LectorDeCsv::columna($encabezados, ['longitud', 'lng', 'lon']),
            'tipo_punto' => LectorDeCsv::columna($encabezados, ['tipo_punto']),
            'referencia' => LectorDeCsv::columna($encabezados, ['referencia']),
            'tipo_material' => LectorDeCsv::columna($encabezados, ['tipo_material', 'material']),
            'nota' => LectorDeCsv::columna($encabezados, ['nota', 'obs', 'observacion', 'observaciones']),
        ];

        $tieneUbicacion = $columnas['direccion'] !== null
            || $columnas['coordenadas'] !== null
            || ($columnas['latitud'] !== null && $columnas['longitud'] !== null);
        if ($columnas['sector'] === null || $columnas['nombre'] === null || ! $tieneUbicacion) {
            throw new ArchivoDeImportacionInvalido('El archivo debe tener las columnas `sector` y `nombre`, y además `coordenadas`, `latitud` y `longitud` o `direccion`.');
        }

        return $columnas;
    }
}
