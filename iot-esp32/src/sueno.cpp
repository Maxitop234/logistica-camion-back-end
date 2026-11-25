#include "sueno.h"

#include <Arduino.h>
#include <esp_sleep.h>

void dormir(uint32_t inicioCicloMs, uint32_t intervaloSeg) {
  const uint64_t transcurridoUs = static_cast<uint64_t>(millis() - inicioCicloMs) * 1000ULL;
  const uint64_t intervaloUs = static_cast<uint64_t>(intervaloSeg) * 1000000ULL;
  const uint64_t esperaUs = transcurridoUs < intervaloUs ? intervaloUs - transcurridoUs : 1000000ULL;
  Serial.printf("[ciclo] durmiendo %llu s\n", esperaUs / 1000000ULL);
  Serial.flush();
  esp_sleep_enable_timer_wakeup(esperaUs);
  esp_deep_sleep_start();
}
