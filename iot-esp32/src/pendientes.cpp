#include "pendientes.h"

#include <Arduino.h>
#include <Preferences.h>
#include <string.h>

namespace {

constexpr const char* NVS_ESPACIO = "ecoruta";
constexpr const char* NVS_CLAVE = "pendientes";

}  // namespace

void ColaPendientes::cargar() {
  datos_.cantidad = 0;
  Preferences prefs;
  if (prefs.begin(NVS_ESPACIO, true)) {
    if (prefs.getBytesLength(NVS_CLAVE) == sizeof(Datos)) {
      prefs.getBytes(NVS_CLAVE, &datos_, sizeof(Datos));
      if (datos_.cantidad > MAX_PENDIENTES) {
        datos_.cantidad = 0;  // Datos corruptos.
      }
    }
    prefs.end();
  }
  cantidadAlCargar_ = datos_.cantidad;
}

void ColaPendientes::guardarSiCambio() {
  if (cantidadAlCargar_ == 0 && datos_.cantidad == 0) {
    return;
  }
  Preferences prefs;
  if (!prefs.begin(NVS_ESPACIO, false)) {
    Serial.println("[nvs] no se pudo abrir: las pendientes se pierden");
    return;
  }
  if (datos_.cantidad == 0) {
    prefs.remove(NVS_CLAVE);
  } else {
    prefs.putBytes(NVS_CLAVE, &datos_, sizeof(Datos));
  }
  prefs.end();
  cantidadAlCargar_ = datos_.cantidad;
}

void ColaPendientes::agregar(const Lectura& lectura) {
  if (datos_.cantidad == MAX_PENDIENTES) {
    quitarPrimeras(1);
    Serial.println("[cola] llena: se descarta la lectura más antigua");
  }
  datos_.items[datos_.cantidad++] = lectura;
}

void ColaPendientes::enviarCon(Transporte& transporte) {
  uint8_t procesadas = 0;
  while (procesadas < datos_.cantidad) {
    const Lectura& l = datos_.items[procesadas];
    const ResultadoEnvio r = transporte.enviar(l);
    if (r == ResultadoEnvio::Reintentar) {
      break;
    }
    if (r == ResultadoEnvio::Descartar) {
      Serial.printf("[cola] descartada %s (rechazada por el servidor)\n", l.uuid);
    }
    procesadas++;
  }
  quitarPrimeras(procesadas);
}

void ColaPendientes::quitarPrimeras(uint8_t n) {
  if (n == 0) {
    return;
  }
  memmove(&datos_.items[0], &datos_.items[n], sizeof(Lectura) * (datos_.cantidad - n));
  datos_.cantidad -= n;
}
