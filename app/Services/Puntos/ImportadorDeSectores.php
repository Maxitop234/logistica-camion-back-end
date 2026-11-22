<?php

namespace App\Services\Puntos;

use App\Models\Sector;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Crea o actualiza los sectores de recolección desde un CSV (BE-04).
 *
 * Acepta el formato `sectores.csv` (`nombre`, `dias_recoleccion`,
 * `periodicidad_semanal`, `puntos_esperados`) y también la planilla de puntos
 * del equipo (`sector`, `dias`, `frecuencia_semanal`), donde el sector se repite
 * en cada fila. El sector se reconoce por su nombre normalizado
 * (`Sector::claveDeNombre`): un sector existente conserva su nombre y solo se
 * actualizan sus días, su frecuencia y, si vienen, los puntos esperados.
 *
 * Si la frecuencia declarada no coincide con la cantidad de días, se avisa y
 * mandan los días, que son los que usa el generador de rutas.
 */
class ImportadorDeSectores
{
    public function __construct(private LectorDeCsv $lector) {}

    /**
     * @throws ArchivoDeImportacionInvalido
     */
    public function importar(string $ruta, bool $simular = false): ReporteDeImportacion
    {
        $csv = $this->lector->leer($ruta);
        $encabezados = $csv['encabezados'];

        $columnaNombre = LectorDeCsv::columna($encabezados, ['sector', 'nombre_sector', 'nombre']);
        $columnaDias = LectorDeCsv::columna($encabezados, ['dias_recoleccion', 'dias']);
        $columnaPeriodicidad = LectorDeCsv::columna($encabezados, ['periodicidad_semanal', 'frecuencia_semanal', 'frecuencia']);
        $columnaEsperados = LectorDeCsv::columna($encabezados, ['puntos_esperados']);

        if ($columnaNombre === null || $columnaDias === null) {
            throw new ArchivoDeImportacionInvalido('El archivo debe tener las columnas `nombre` (o `sector`) y `dias_recoleccion` (o `dias`).');
        }

        $reporte = new ReporteDeImportacion($simular);
        $sectores = [];

        foreach ($csv['filas'] as ['linea' => $linea, 'valores' => $fila]) {
            $nombre = $fila[$columnaNombre] ?? '';
            $clave = Sector::claveDeNombre($nombre);
            if ($clave === '') {
                $reporte->error($linea, 'Falta el nombre del sector.');

                continue;
            }

            $textoDias = $fila[$columnaDias] ?? '';
            $dias = Sector::diasDesdeTexto($textoDias);
            if ($dias === null) {
                $reporte->error($linea, "Los días «{$textoDias}» del sector «{$nombre}» no se reconocen (usa 1 a 7 o lunes a domingo).");

                continue;
            }

            $textoPeriodicidad = $columnaPeriodicidad !== null ? ($fila[$columnaPeriodicidad] ?? '') : '';
            if ($textoPeriodicidad !== '' && (! ctype_digit($textoPeriodicidad) || (int) $textoPeriodicidad < 1 || (int) $textoPeriodicidad > 7)) {
                $reporte->error($linea, "La frecuencia semanal «{$textoPeriodicidad}» del sector «{$nombre}» debe ser un entero de 1 a 7.");

                continue;
            }
            $periodicidad = $textoPeriodicidad === '' ? count($dias) : (int) $textoPeriodicidad;

            $textoEsperados = $columnaEsperados !== null ? ($fila[$columnaEsperados] ?? '') : '';
            if ($textoEsperados !== '' && ! ctype_digit($textoEsperados)) {
                $reporte->error($linea, "Los puntos esperados «{$textoEsperados}» del sector «{$nombre}» deben ser un entero.");

                continue;
            }
            $esperados = $textoEsperados === '' ? null : (int) $textoEsperados;

            if (isset($sectores[$clave])) {
                $primero = $sectores[$clave];
                if ($primero['dias'] !== $dias || $primero['periodicidad'] !== $periodicidad) {
                    $reporte->aviso($linea, "El sector «{$nombre}» trae días o frecuencia distintos a los de la línea {$primero['linea']}; se conservan los de esa línea.");
                }

                continue;
            }

            $sectores[$clave] = [
                'linea' => $linea,
                'nombre' => $nombre,
                'dias' => $dias,
                'periodicidad' => $periodicidad,
                'puntos_esperados' => $esperados,
            ];
        }

        DB::beginTransaction();
        try {
            $existentes = Sector::query()->get()->keyBy(static fn (Sector $sector): string => Sector::claveDeNombre($sector->nombre));

            foreach ($sectores as $clave => $datos) {
                $this->guardar($datos, $existentes->get($clave), $reporte);
            }
        } catch (Throwable $error) {
            DB::rollBack();

            throw $error;
        }

        $simular ? DB::rollBack() : DB::commit();

        return $reporte;
    }

    /**
     * @param  array{linea: int, nombre: string, dias: array<int, int>, periodicidad: int, puntos_esperados: int|null}  $datos
     */
    private function guardar(array $datos, ?Sector $sector, ReporteDeImportacion $reporte): void
    {
        if ($sector === null) {
            ['nombre' => $nombre, 'descripcion' => $descripcion] = $this->nombreYDescripcion($datos['nombre']);
            $sector = new Sector(['nombre' => $nombre, 'descripcion' => $descripcion, 'activo' => true]);
        }

        $sector->fill([
            'dias_recoleccion' => $datos['dias'],
            'periodicidad_semanal' => $datos['periodicidad'],
        ]);
        if ($datos['puntos_esperados'] !== null) {
            $sector->puntos_esperados = $datos['puntos_esperados'];
        }

        if (! $sector->diasCoincidenConPeriodicidad()) {
            $reporte->aviso($datos['linea'], sprintf(
                'El sector «%s» declara %d vez(ces) por semana pero marca %d día(s); se usan los días (%s).',
                $sector->nombre,
                $datos['periodicidad'],
                count($datos['dias']),
                implode(', ', $sector->nombresDias()),
            ));
        }

        if (! $sector->exists) {
            $accion = 'creado';
            $reporte->creados++;
        } elseif ($sector->isDirty()) {
            $accion = 'actualizado';
            $reporte->actualizados++;
        } else {
            $accion = 'sin cambios';
            $reporte->sinCambios++;
        }

        if ($accion !== 'sin cambios') {
            $sector->save();
        }

        $reporte->filas[] = [
            'sector' => $sector->nombre,
            'dias' => implode(', ', $sector->nombresDias()),
            'frecuencia_semanal' => $sector->periodicidad_semanal,
            'puntos_esperados' => $sector->puntos_esperados,
            'resultado' => $accion,
        ];
    }

    /**
     * Nombre limpio para un sector nuevo: sin espacios antes de las comas y con
     * el paréntesis descriptivo de la planilla como descripción.
     *
     * @return array{nombre: string, descripcion: string|null}
     */
    private function nombreYDescripcion(string $nombre): array
    {
        $descripcion = null;
        if (preg_match('/\(([^)]*)\)?/u', $nombre, $parentesis) === 1) {
            $descripcion = $this->limpiar($parentesis[1]) ?: null;
        }

        $sinParentesis = $this->limpiar((string) preg_replace('/\([^)]*\)?/u', ' ', $nombre));

        return ['nombre' => mb_substr($sinParentesis, 0, 150), 'descripcion' => $descripcion];
    }

    private function limpiar(string $texto): string
    {
        $comas = (string) preg_replace('/\s*,\s*/u', ', ', $texto);

        return trim((string) preg_replace('/\s+/u', ' ', $comas), " ,\t\n\r\0\x0B");
    }
}
