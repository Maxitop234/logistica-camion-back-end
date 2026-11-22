<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Zona de operación
    |--------------------------------------------------------------------------
    |
    | Caja que encierra Temuco (incluida Labranza) y Padre Las Casas. Las
    | coordenadas de un punto de reciclaje deben quedar dentro de ella.
    |
    */

    'zona' => [
        'nombre' => 'Temuco–Padre Las Casas',
        'latitud_min' => (float) env('ZONA_LATITUD_MIN', -38.85),
        'latitud_max' => (float) env('ZONA_LATITUD_MAX', -38.62),
        'longitud_min' => (float) env('ZONA_LONGITUD_MIN', -72.85),
        'longitud_max' => (float) env('ZONA_LONGITUD_MAX', -72.45),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zona horaria de la operación
    |--------------------------------------------------------------------------
    |
    | Los días de recolección de cada sector se interpretan en la hora local
    | de Chile; las fechas se guardan y se exponen en UTC.
    |
    */

    'zona_horaria' => env('ZONA_HORARIA_OPERACION', 'America/Santiago'),

    /*
    |--------------------------------------------------------------------------
    | Vigencia del estado de llenado (ADR-0013)
    |--------------------------------------------------------------------------
    */

    'estado' => [
        'vigencia_sensor_horas' => (int) env('ESTADO_SENSOR_VIGENCIA_HORAS', 24),
    ],

];
