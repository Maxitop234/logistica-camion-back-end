// Deep sleep entre ciclos. No depende del transporte: la radio se apaga antes
// con Transporte::apagar().
#pragma once

#include <stdint.h>

// Duerme `intervaloSeg` descontando lo que tardó el ciclo (mínimo 1 s). No
// vuelve: al despertar el ESP32 reinicia en setup().
[[noreturn]] void dormir(uint32_t inicioCicloMs, uint32_t intervaloSeg);
