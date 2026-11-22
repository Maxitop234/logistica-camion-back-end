<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sensores IoT (ESP32 + ultrasónico)
    |--------------------------------------------------------------------------
    |
    | Contrato con el firmware en docs/IOT_FIRMWARE_CONTRATO.md. Las zonas
    | ciegas y los alcances son valores típicos de las hojas de datos de cada
    | modelo: POR CONFIRMAR con el sensor definitivo (IOT-01).
    |
    */

    'intervalo_defecto_seg' => (int) env('IOT_INTERVALO_DEFECTO_SEG', 3600),

    // Un sensor "no reporta" cuando pasan más de N intervalos desde su última conexión.
    'intervalos_sin_reporte' => (int) env('IOT_INTERVALOS_SIN_REPORTE', 2),

    'bateria_minima_v' => (float) env('IOT_BATERIA_MINIMA_V', 3.40),

    // Lecturas inválidas seguidas que disparan la alerta.
    'invalidas_para_alerta' => (int) env('IOT_INVALIDAS_PARA_ALERTA', 3),

    // Un valor medido hasta este margen por debajo del fondo declarado se acepta como 0 %.
    'tolerancia_fondo_cm' => (float) env('IOT_TOLERANCIA_FONDO_CM', 10),

    'sensores' => [
        'hc_sr04' => ['zona_ciega_cm' => 2.0, 'alcance_cm' => 400.0, 'distancia_lleno_cm' => 5.0],
        'jsn_sr04t' => ['zona_ciega_cm' => 20.0, 'alcance_cm' => 600.0, 'distancia_lleno_cm' => 25.0],
    ],

    'vinculacion' => [
        // Intentos por minuto: por IP y por código de hardware (frena la fuerza bruta del código de etiqueta).
        'intentos_por_ip' => (int) env('IOT_VINCULACION_INTENTOS_IP', 10),
        'intentos_por_hardware' => (int) env('IOT_VINCULACION_INTENTOS_HW', 20),
        'prefijo_hardware' => env('IOT_PREFIJO_HARDWARE', 'ECO'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Simulador de sensores (solo con APP_DEMO_MODE=true)
    |--------------------------------------------------------------------------
    */

    'simulador' => [
        'intervalo_seg' => (int) env('IOT_SIMULADOR_INTERVALO_SEG', 1800),
        // Máximo de lecturas pendientes que se completan por sensor en cada llamada.
        'maximo_por_sensor' => (int) env('IOT_SIMULADOR_MAXIMO_POR_SENSOR', 24),
        // Segundos mínimos entre dos completados disparados por consultas.
        'espera_seg' => (int) env('IOT_SIMULADOR_ESPERA_SEG', 60),
    ],

];
