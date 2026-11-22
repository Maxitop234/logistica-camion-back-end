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

    /*
    |--------------------------------------------------------------------------
    | Detección de desvíos (traza real contra ruta planificada)
    |--------------------------------------------------------------------------
    |
    | Un desvío es un tramo en que la traza GPS queda a más de `umbral_m` de la
    | ruta planificada y recorre al menos `longitud_minima_m`. `umbral_reporte`
    | es la cantidad de desvíos sobre el mismo tramo para que aparezca en el
    | reporte de calles problemáticas.
    |
    */

    'desvios' => [
        'umbral_m' => (float) env('DESVIO_UMBRAL_M', 40),
        'longitud_minima_m' => (float) env('DESVIO_LONGITUD_MINIMA_M', 100),
        'umbral_reporte' => (int) env('DESVIO_UMBRAL_REPORTE', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sesiones (vida de los tokens por rol)
    |--------------------------------------------------------------------------
    |
    | Al vencer, la API responde 401 y la app vuelve al login conservando la
    | cola offline. El administrador usa una sesión corta; el chofer, larga.
    |
    */

    'sesion' => [
        'administrador_horas' => (int) env('SESION_ADMIN_HORAS', 12),
        'chofer_dias' => (int) env('SESION_CHOFER_DIAS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recuperación de contraseña por correo (solo administradores)
    |--------------------------------------------------------------------------
    |
    | Opcional: solo se ofrece si hay un correo real configurado (MAIL_MAILER
    | distinto de log/array) y PASSWORD_RESET_EMAIL_ENABLED=true. `frontend_url`
    | es la base de la app web donde se abre el enlace.
    |
    */

    'recuperacion_por_correo' => [
        'habilitada' => (bool) env('PASSWORD_RESET_EMAIL_ENABLED', false),
        'frontend_url' => rtrim((string) env('FRONTEND_URL', ''), '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Contraseñas
    |--------------------------------------------------------------------------
    */

    'password' => [
        'largo_minimo' => 10,
    ],

];
