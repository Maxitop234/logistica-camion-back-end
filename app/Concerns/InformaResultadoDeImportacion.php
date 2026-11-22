<?php

namespace App\Concerns;

use App\Services\Puntos\ReporteDeImportacion;
use Illuminate\Console\Command;

/**
 * Salida común de los comandos de importación (BE-04): ruta del archivo,
 * errores y avisos por línea.
 *
 * @mixin Command
 */
trait InformaResultadoDeImportacion
{
    /**
     * Ruta del argumento `archivo`; las relativas se resuelven desde la raíz del proyecto.
     */
    protected function rutaDelArchivo(): string
    {
        $archivo = $this->argument('archivo');
        $archivo = is_string($archivo) ? trim($archivo) : '';

        return str_starts_with($archivo, '/') ? $archivo : base_path($archivo);
    }

    protected function informarMensajes(ReporteDeImportacion $reporte): void
    {
        if ($reporte->simulado) {
            $this->warn('Simulación (--dry-run): no se guardó ningún cambio.');
        }

        foreach ($reporte->errores as $error) {
            $this->error($this->conLinea($error));
        }
        foreach ($reporte->avisos as $aviso) {
            $this->warn($this->conLinea($aviso));
        }
    }

    /**
     * @param  array{linea: int|null, mensaje: string}  $mensaje
     */
    private function conLinea(array $mensaje): string
    {
        return $mensaje['linea'] === null ? $mensaje['mensaje'] : "Línea {$mensaje['linea']}: {$mensaje['mensaje']}";
    }
}
