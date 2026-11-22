<?php

namespace App\Services\Puntos;

/**
 * Resultado de una importación de sectores o de puntos (BE-04): contadores,
 * errores y avisos por número de línea, y las filas de detalle o de
 * conciliación que el comando muestra en una tabla.
 */
class ReporteDeImportacion
{
    public int $creados = 0;

    public int $actualizados = 0;

    public int $sinCambios = 0;

    public int $omitidos = 0;

    /** @var list<array{linea: int|null, mensaje: string}> */
    public array $errores = [];

    /** @var list<array{linea: int|null, mensaje: string}> */
    public array $avisos = [];

    /** @var list<array<string, int|string|null>> */
    public array $filas = [];

    public function __construct(public readonly bool $simulado = false) {}

    /**
     * Fila que no se importó.
     */
    public function error(?int $linea, string $mensaje): void
    {
        $this->errores[] = ['linea' => $linea, 'mensaje' => $mensaje];
    }

    /**
     * Situación que conviene revisar pero que no impidió importar.
     */
    public function aviso(?int $linea, string $mensaje): void
    {
        $this->avisos[] = ['linea' => $linea, 'mensaje' => $mensaje];
    }
}
