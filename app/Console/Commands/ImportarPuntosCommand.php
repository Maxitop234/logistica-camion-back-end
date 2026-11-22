<?php

namespace App\Console\Commands;

use App\Concerns\InformaResultadoDeImportacion;
use App\Services\Puntos\ArchivoDeImportacionInvalido;
use App\Services\Puntos\ImportadorDePuntos;
use Illuminate\Console\Command;

class ImportarPuntosCommand extends Command
{
    use InformaResultadoDeImportacion;

    protected $signature = 'puntos:importar
        {archivo : Ruta del CSV (p. ej. storage/app/private/importaciones/puntos.csv)}
        {--geocodificar : Busca en OpenStreetMap las coordenadas de las filas que solo traen dirección (1 consulta por segundo)}
        {--dry-run : Valida el archivo y muestra la conciliación sin guardar cambios}';

    protected $description = 'Crea o actualiza los puntos de reciclaje reales desde un CSV, sin duplicar al reimportar, y concilia por sector (BE-04).';

    public function handle(ImportadorDePuntos $importador): int
    {
        $simular = (bool) $this->option('dry-run');

        try {
            $reporte = $importador->importar(
                $this->rutaDelArchivo(),
                (bool) $this->option('geocodificar'),
                $simular,
            );
        } catch (ArchivoDeImportacionInvalido $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->informarMensajes($reporte);

        $this->table(
            ['Sector', 'En archivo', 'Creados', 'Actualizados', 'Sin cambios', 'Omitidos', 'Activos en base', 'Esperados', 'Diferencia', 'Por revisar'],
            $reporte->filas,
        );

        $this->info(sprintf(
            'Puntos: %d creados, %d actualizados, %d sin cambios, %d omitidos.',
            $reporte->creados,
            $reporte->actualizados,
            $reporte->sinCambios,
            $reporte->omitidos,
        ));

        return self::SUCCESS;
    }
}
