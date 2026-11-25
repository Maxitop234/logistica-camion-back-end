// Hora del nodo. La pone en hora el transporte al conectar (NTP por Wi-Fi o,
// a futuro, la red celular) y se conserva durante el deep sleep.
#pragma once

#include <stdint.h>

// Si la hora es creíble (posterior a EPOCH_VALIDO_MIN).
bool relojEnHora();

// Segundos Unix UTC; 0 si el reloj no está en hora.
uint32_t ahoraEpoch();
