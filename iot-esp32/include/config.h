// Parámetros del nodo que no son secretos.
#pragma once

#include <stdint.h>

// Pines (ver diagrama en README.md).
constexpr uint8_t PIN_TRIG = 5;
constexpr uint8_t PIN_ECHO = 18;      // Con divisor de tensión: el ECHO del HC-SR04 sale a 5 V.
constexpr uint8_t PIN_BATERIA = 34;   // ADC1, solo entrada.

// Medición.
constexpr uint8_t MUESTRAS = 5;                    // Se envía la mediana.
constexpr uint32_t PAUSA_ENTRE_MUESTRAS_MS = 60;   // El HC-SR04 pide >= 60 ms entre disparos.
constexpr uint32_t TIMEOUT_ECO_US = 30000;         // ~5 m ida y vuelta; sin eco = muestra perdida.
constexpr float VELOCIDAD_SONIDO_CM_US = 0.0343f;  // A ~20 °C.

// Batería: factor del divisor resistivo (R1 = R2 = 100 kΩ → 2,0).
constexpr float FACTOR_DIVISOR_BATERIA = 2.0f;
constexpr uint8_t MUESTRAS_BATERIA = 8;

// Ciclo. INTERVALO_SEG se define en secrets.h (300 = 5 min por defecto).
constexpr uint32_t TIMEOUT_WIFI_MS = 15000;
constexpr uint32_t TIMEOUT_NTP_MS = 8000;
// El servidor gratuito (Render) tarda ~1 min en despertar: se espera hasta 90 s
// por lectura. HTTPClient acepta a lo sumo 65 s por intento, así que dentro de
// ese plazo se reintenta ante timeout o 502/503/504.
constexpr uint32_t TIMEOUT_HTTP_MS = 90000;
constexpr uint32_t TIMEOUT_INTENTO_MAX_MS = 65000;
constexpr uint32_t PAUSA_REINTENTO_MS = 3000;
constexpr uint8_t MAX_INTENTOS_HTTP = 3;

// Lecturas guardadas sin conexión (la más antigua se descarta al llenarse).
constexpr uint8_t MAX_PENDIENTES = 50;

// Cualquier hora anterior (2024-01-01) significa que el reloj no está en hora.
constexpr uint32_t EPOCH_VALIDO_MIN = 1704067200UL;
