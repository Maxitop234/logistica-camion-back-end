#include "lectura.h"

#include <esp_random.h>
#include <math.h>
#include <stdio.h>
#include <string.h>
#include <time.h>

void generarUuid(char* destino) {
  uint8_t b[16];
  for (uint8_t i = 0; i < 16; i += 4) {
    const uint32_t r = esp_random();
    memcpy(b + i, &r, 4);
  }
  b[6] = (b[6] & 0x0F) | 0x40;  // Versión 4.
  b[8] = (b[8] & 0x3F) | 0x80;  // Variante RFC 4122.
  snprintf(destino, 37,
           "%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x",
           b[0], b[1], b[2], b[3], b[4], b[5], b[6], b[7], b[8], b[9], b[10], b[11], b[12],
           b[13], b[14], b[15]);
}

void lecturaAJson(const Lectura& l, char* destino, size_t largo) {
  char distancia[24];
  char bateria[16];
  char medido[48];
  if (isnan(l.distanciaCm)) {
    // Sin eco: el servidor exige `error` cuando no hay distancia (diagnóstico).
    snprintf(distancia, sizeof(distancia), "null,\"error\":\"sin_eco\"");
  } else {
    snprintf(distancia, sizeof(distancia), "%.1f", l.distanciaCm);
  }
  if (isnan(l.bateriaV)) {
    snprintf(bateria, sizeof(bateria), "null");
  } else {
    snprintf(bateria, sizeof(bateria), "%.2f", l.bateriaV);
  }
  medido[0] = '\0';
  if (l.medidoEn != 0) {
    const time_t t = l.medidoEn;
    struct tm utc;
    gmtime_r(&t, &utc);
    char iso[24];
    strftime(iso, sizeof(iso), "%Y-%m-%dT%H:%M:%SZ", &utc);
    snprintf(medido, sizeof(medido), ",\"medido_en\":\"%s\"", iso);
  }
  snprintf(destino, largo,
           "{\"distancia_cm\":%s,\"bateria_voltaje\":%s,\"lectura_uuid\":\"%s\"%s}",
           distancia, bateria, l.uuid, medido);
}
