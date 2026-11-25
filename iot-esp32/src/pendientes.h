// Lecturas pendientes de envío, guardadas en NVS (sobreviven a cortes de
// energía). No depende del transporte concreto: envía a través de la interfaz.
#pragma once

#include <stdint.h>

#include "config.h"
#include "lectura.h"
#include "transporte.h"

class ColaPendientes {
 public:
  // Lee la cola guardada (vacía la primera vez o si está corrupta).
  void cargar();

  // Escribe en NVS solo si había o hay pendientes (cuida el desgaste de la flash).
  void guardarSiCambio();

  // Agrega al final; si está llena descarta la más antigua.
  void agregar(const Lectura& lectura);

  // Envía de la más antigua a la más nueva y se detiene en la primera que deba
  // reintentarse. Quita las aceptadas y las descartadas.
  void enviarCon(Transporte& transporte);

  uint8_t cantidad() const { return datos_.cantidad; }

 private:
  struct Datos {
    uint8_t cantidad;
    Lectura items[MAX_PENDIENTES];  // De la más antigua a la más nueva.
  };

  void quitarPrimeras(uint8_t n);

  Datos datos_{};
  uint8_t cantidadAlCargar_ = 0;
};
