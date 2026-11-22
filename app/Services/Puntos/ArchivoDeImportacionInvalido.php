<?php

namespace App\Services\Puntos;

use RuntimeException;

/**
 * El archivo de importación no se puede procesar (no existe, no está en UTF-8
 * o le faltan columnas obligatorias). Los errores de una fila no lanzan esta
 * excepción: se informan en el reporte con su número de línea.
 */
class ArchivoDeImportacionInvalido extends RuntimeException {}
