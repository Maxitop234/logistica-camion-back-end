// Lectura del sensor: lo que se mide, se guarda sin conexión y se envía.
#pragma once

#include <stddef.h>
#include <stdint.h>

// `medidoEn` = 0 si el reloj no estaba en hora al medir (el servidor usa
// entonces la hora de recepción).
struct Lectura {
  float distanciaCm;  // NAN si ninguna muestra tuvo eco.
  float bateriaV;     // NAN si no se pudo leer.
  uint32_t medidoEn;  // Segundos Unix UTC.
  char uuid[37];      // UUID v4: el servidor descarta duplicados por este valor.
};

// Genera un UUID v4 aleatorio en `destino` (37 bytes con el terminador).
void generarUuid(char* destino);

// Cuerpo JSON de POST /api/v1/iot/lecturas, común a cualquier transporte.
void lecturaAJson(const Lectura& lectura, char* destino, size_t largo);
