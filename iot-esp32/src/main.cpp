// Nodo sensor de llenado EcoRuta (ESP32 + HC-SR04).
//
// Cada ciclo: mide la distancia (mediana de 5 muestras) y la batería, la suma
// a las lecturas pendientes, se conecta por el transporte, envía las
// pendientes de la más antigua a la más nueva y duerme INTERVALO_SEG. Sin red,
// las lecturas quedan en NVS y se reenvían en el siguiente ciclo con conexión.
// El servidor descarta duplicados por `lectura_uuid`, así que reenviar es seguro.
//
// El nodo no corrige distancias: la calibración vive en el servidor.

#include <Arduino.h>

#include "config.h"

#if __has_include("secrets.h")
#include "secrets.h"
#else
#error "Falta include/secrets.h: copia include/secrets.example.h y completa los valores."
#endif

#include "lectura.h"
#include "medicion.h"
#include "pendientes.h"
#include "reloj.h"
#include "sueno.h"
#include "transporte_wifi_https.h"

#ifndef INTERVALO_SEG
#define INTERVALO_SEG 300
#endif

namespace {

// Medio de envío. Para usar otro (p. ej. un módem celular) basta con cambiar
// esta instancia por otra implementación de Transporte.
TransporteWifiHttps transporte;

ColaPendientes cola;

}  // namespace

void setup() {
  const uint32_t inicioCiclo = millis();
  Serial.begin(115200);
#ifdef TEST_ONLY
  Serial.println("[aviso] TEST_ONLY: HTTPS sin validar el certificado");
#endif
  iniciarMedicion();

  // Se mide antes de encender la radio (menos ruido en el ADC).
  Lectura nueva;
  nueva.distanciaCm = medirDistanciaCm();
  nueva.bateriaV = medirBateriaV();
  nueva.medidoEn = ahoraEpoch();
  const uint32_t medidaEnMs = millis();
  generarUuid(nueva.uuid);
  if (isnan(nueva.distanciaCm)) {
    Serial.printf("[medicion] distancia: sin eco · bateria=%.2f V\n", nueva.bateriaV);
  } else {
    Serial.printf("[medicion] distancia=%.1f cm · bateria=%.2f V\n", nueva.distanciaCm, nueva.bateriaV);
  }

  cola.cargar();

  Serial.printf("[transporte] %s\n", transporte.nombre());
  if (transporte.conectar()) {
    // Primer arranque sin hora: se fecha la lectura recién tomada restando lo
    // que tardó la conexión. Las guardadas sin hora se envían sin `medido_en`.
    if (nueva.medidoEn == 0 && relojEnHora()) {
      nueva.medidoEn = ahoraEpoch() - (millis() - medidaEnMs) / 1000;
    }
    cola.agregar(nueva);
    cola.enviarCon(transporte);
  } else {
    Serial.println("[ciclo] sin conexión: la lectura queda guardada");
    cola.agregar(nueva);
  }
  transporte.apagar();

  cola.guardarSiCambio();
  Serial.printf("[cola] lecturas pendientes: %u\n", cola.cantidad());
  dormir(inicioCiclo, INTERVALO_SEG);
}

void loop() {
  // No se usa: cada ciclo termina en deep sleep y el ESP32 reinicia en setup().
}
