// Copia este archivo como include/secrets.h y completa los valores.
// include/secrets.h está ignorado por git: nunca subas credenciales reales.
#pragma once

#define WIFI_SSID "nombre-de-la-red"
#define WIFI_PASSWORD "clave-de-la-red"

// Sin barra final. Ejemplos: https://<servicio>.onrender.com · http://192.168.1.50
#define API_URL "https://ejemplo.onrender.com"

// Token propio del sensor, entregado una sola vez al aprobarlo en el panel
// (ver README, "Obtener el token").
#define IOT_TOKEN "eco_iot_reemplazar"

// Segundos entre lecturas: 300 (5 min) en operación; 15 sirve para probar.
#define INTERVALO_SEG 300

// HTTPS siempre valida el certificado del servidor con las raíces de
// include/raices_ca.h. Solo para un banco de pruebas sin salida: descomentar
// TEST_ONLY desactiva esa validación. Nunca en un sensor instalado.
// #define TEST_ONLY
