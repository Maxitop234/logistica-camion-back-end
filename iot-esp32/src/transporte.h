// Interfaz del medio por el que el nodo entrega sus lecturas a la API.
//
// Hoy existe TransporteWifiHttps. Un transporte por módem celular (LTE-M o
// NB-IoT) implementará esta misma interfaz sin cambiar la medición, la cola de
// pendientes ni el sueño.
#pragma once

#include "lectura.h"

// Qué hacer con una lectura después de intentar enviarla.
enum class ResultadoEnvio {
  Aceptada,   // El servidor la registró (o ya la tenía): se quita de la cola.
  Descartar,  // El servidor nunca la aceptará (p. ej. 422): se quita y se sigue.
  Reintentar  // Sin red, credencial rechazada, límite o error del servidor: se
              // conservan esta y las siguientes para el próximo ciclo.
};

class Transporte {
 public:
  virtual ~Transporte() = default;

  // Enciende la radio, se une a la red y deja el reloj en hora si puede.
  // Devuelve false si no hay conexión (las lecturas quedan pendientes).
  virtual bool conectar() = 0;

  // Entrega una lectura a la API. Solo se llama tras un conectar() exitoso.
  virtual ResultadoEnvio enviar(const Lectura& lectura) = 0;

  // Apaga la radio antes del deep sleep.
  virtual void apagar() = 0;

  // Nombre para el monitor serie.
  virtual const char* nombre() const = 0;
};
