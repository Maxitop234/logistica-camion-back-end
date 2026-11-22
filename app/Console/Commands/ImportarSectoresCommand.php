<?php

namespace App\Console\Commands;

use App\Concerns\InformaResultadoDeImportacion;
use App\Services\Puntos\ArchivoDeImportacionInvalido;
use App\Services\Puntos\ImportadorDeSectores;
use Illuminate\Console\Command;

class ImportarSectoresCommand extends Command
{
    use InformaResultadoDeImportacion;

    protected $signature = 'sectores:importar
        {archivo : Ruta del CSV (p. ej. storage/app/private/importaciones/sectores.csv)}
        {--dry-run : Valida el archivo y muestra el resultado sin guardar cambios}';

    protected $description = 'Crea o actualiza los sectores de recolección y sus días desde un CSV, sin duplicar al reimportar (BE-04).';

    public function handle(ImportadorDeSectores $importador): int
    {
        $simular = (bool) $this->option('dry-run');

        try {
            $reporte = $importador->importar($this->rutaDelArchivo(), $simular);
        } catch (ArchivoDeImportacionInvalido $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->informarMensajes($reporte);

        if ($reporte->filas !== []) {
            $this->table(
                ['Sector', 'Días', 'Frecuencia semanal', 'Puntos esperados', 'Resultado'],
                $reporte->filas,
            );
        }

        $this->info(sprintf(
            'Sectores: %d creados, %d actualizados, %d sin cambios, %d filas con error.',
            $reporte->creados,
            $reporte->actualizados,
            $reporte->sinCambios,
            count($reporte->errores),
        ));

        return self::SUCCESS;
    }
}
