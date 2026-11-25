// Transporte por Wi-Fi + HTTPS (HTTP si API_URL empieza con http://).
#pragma once

#include "transporte.h"

class TransporteWifiHttps : public Transporte {
 public:
  bool conectar() override;
  ResultadoEnvio enviar(const Lectura& lectura) override;
  void apagar() override;
  const char* nombre() const override { return "Wi-Fi + HTTPS"; }

 private:
  // Un intento de POST que espera a lo sumo `timeoutMs`. Devuelve el código
  // HTTP o un error negativo de HTTPClient.
  int postUnaVez(const char* cuerpo, uint32_t timeoutMs);
};
