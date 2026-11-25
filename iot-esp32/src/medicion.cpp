#include "medicion.h"

#include <Arduino.h>

#include "config.h"

namespace {

float medirMuestraCm() {
  digitalWrite(PIN_TRIG, LOW);
  delayMicroseconds(4);
  digitalWrite(PIN_TRIG, HIGH);
  delayMicroseconds(10);
  digitalWrite(PIN_TRIG, LOW);
  const unsigned long us = pulseIn(PIN_ECHO, HIGH, TIMEOUT_ECO_US);
  return us == 0 ? NAN : us * VELOCIDAD_SONIDO_CM_US / 2.0f;
}

}  // namespace

void iniciarMedicion() {
  pinMode(PIN_TRIG, OUTPUT);
  digitalWrite(PIN_TRIG, LOW);
  pinMode(PIN_ECHO, INPUT);
  analogSetPinAttenuation(PIN_BATERIA, ADC_11db);
}

float medirDistanciaCm() {
  float validas[MUESTRAS];
  uint8_t n = 0;
  for (uint8_t i = 0; i < MUESTRAS; i++) {
    const float cm = medirMuestraCm();
    if (!isnan(cm)) {
      validas[n++] = cm;
    }
    delay(PAUSA_ENTRE_MUESTRAS_MS);
  }
  if (n == 0) {
    return NAN;
  }
  // Inserción: a lo sumo MUESTRAS elementos.
  for (uint8_t i = 1; i < n; i++) {
    const float v = validas[i];
    int8_t j = i - 1;
    while (j >= 0 && validas[j] > v) {
      validas[j + 1] = validas[j];
      j--;
    }
    validas[j + 1] = v;
  }
  return n % 2 == 1 ? validas[n / 2] : (validas[n / 2 - 1] + validas[n / 2]) / 2.0f;
}

float medirBateriaV() {
  uint32_t suma = 0;
  for (uint8_t i = 0; i < MUESTRAS_BATERIA; i++) {
    suma += analogReadMilliVolts(PIN_BATERIA);  // Usa la calibración de fábrica del ADC.
    delay(2);
  }
  const float v = (suma / static_cast<float>(MUESTRAS_BATERIA)) / 1000.0f * FACTOR_DIVISOR_BATERIA;
  return v > 0.5f ? v : NAN;  // ~0 V: divisor desconectado.
}
