<?php

namespace App\Console\Commands;

use App\Services\Iot\VinculacionDeDispositivos;
use Illuminate\Console\Command;

class GenerarEtiquetasIotCommand extends Command
{
    protected $signature = 'iot:generar-etiquetas
        {cantidad : Cantidad de códigos a generar (1-500)}
        {--lote= : Nombre del lote (p. ej. piloto-2026-11)}
        {--prefijo= : Prefijo del código de hardware (por defecto IOT_PREFIJO_HARDWARE)}
        {--salida= : Archivo CSV de salida (sin esta opción se imprime por pantalla)}';

    protected $description = 'Genera códigos de vinculación por lote y entrega un CSV (codigo_hardware, codigo, qr) para imprimir las etiquetas.';

    public function handle(VinculacionDeDispositivos $vinculacion): int
    {
        $cantidad = (int) $this->argument('cantidad');
        if ($cantidad < 1 || $cantidad > 500) {
            $this->error('La cantidad debe estar entre 1 y 500.');

            return self::FAILURE;
        }

        $lote = $this->option('lote');
        $prefijo = $this->option('prefijo');
        $filas = $vinculacion->generarCodigos(
            $cantidad,
            is_string($lote) && $lote !== '' ? $lote : null,
            is_string($prefijo) && $prefijo !== '' ? $prefijo : null,
        );

        $lineas = ['codigo_hardware,codigo,qr'];
        foreach ($filas as $fila) {
            // El QR lleva ambos datos: el panel los separa al escanear.
            $lineas[] = "{$fila['codigo_hardware']},{$fila['codigo']},{$fila['codigo_hardware']}|{$fila['codigo']}";
        }
        $csv = implode("\n", $lineas)."\n";

        $salida = $this->option('salida');
        if (is_string($salida) && $salida !== '') {
            file_put_contents($salida, $csv);
            $this->info("{$cantidad} etiquetas guardadas en {$salida}. Los códigos no se pueden volver a ver: guarda el archivo en un lugar seguro.");
        } else {
            $this->line($csv);
        }

        return self::SUCCESS;
    }
}
