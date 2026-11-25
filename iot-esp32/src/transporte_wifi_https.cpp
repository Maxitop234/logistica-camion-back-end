#include "transporte_wifi_https.h"

#include <Arduino.h>
#include <HTTPClient.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <time.h>

#include "config.h"
#include "raices_ca.h"
#include "reloj.h"
#include "secrets.h"

namespace {

bool esperarYReintentar(int codigo) {
  return codigo < 0 || codigo == 502 || codigo == 503 || codigo == 504;
}

// Pone en hora el reloj por NTP.
void sincronizarHora() {
  configTime(0, 0, "pool.ntp.org", "time.google.com");
  const uint32_t inicio = millis();
  while (!relojEnHora() && millis() - inicio < TIMEOUT_NTP_MS) {
    delay(100);
  }
}

}  // namespace

bool TransporteWifiHttps::conectar() {
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  const uint32_t inicio = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - inicio < TIMEOUT_WIFI_MS) {
    delay(200);
  }
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[wifi] sin conexión");
    return false;
  }
  Serial.printf("[wifi] conectado a %s · IP %s · RSSI %d dBm\n", WIFI_SSID,
                WiFi.localIP().toString().c_str(), WiFi.RSSI());
  sincronizarHora();
  if (!relojEnHora()) {
    Serial.println("[ntp] sin hora: HTTPS no podrá validar el certificado");
  }
  return true;
}

int TransporteWifiHttps::postUnaVez(const char* cuerpo, uint32_t timeoutMs) {
  const String url = String(API_URL) + "/api/v1/iot/lecturas";
  const bool https = url.startsWith("https://");

  WiFiClientSecure seguro;
  WiFiClient plano;
  if (https) {
#ifdef TEST_ONLY
    seguro.setInsecure();  // Solo banco de pruebas: no valida el certificado.
#else
    seguro.setCACert(RAICES_CA);
#endif
  }

  HTTPClient http;
  http.setConnectTimeout(static_cast<int32_t>(timeoutMs));
  http.setTimeout(static_cast<uint16_t>(timeoutMs));  // uint16_t: <= 65 s.
  const bool listo = https ? http.begin(seguro, url) : http.begin(plano, url);
  if (!listo) {
    return HTTPC_ERROR_CONNECTION_REFUSED;
  }
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Accept", "application/json");
  http.addHeader("Authorization", String("Bearer ") + IOT_TOKEN);
  const int codigo = http.POST(String(cuerpo));
  http.end();
  return codigo;
}

ResultadoEnvio TransporteWifiHttps::enviar(const Lectura& l) {
  char cuerpo[256];
  lecturaAJson(l, cuerpo, sizeof(cuerpo));

  // HTTPClient no admite timeouts sobre 65 s: para esperar hasta 90 s a que el
  // servidor despierte se reintenta dentro del plazo (seguro: el servidor
  // ignora un `lectura_uuid` repetido).
  const uint32_t inicio = millis();
  int codigo = 0;
  for (uint8_t intento = 1; intento <= MAX_INTENTOS_HTTP; intento++) {
    const uint32_t usado = millis() - inicio;
    if (usado >= TIMEOUT_HTTP_MS) {
      break;
    }
    const uint32_t restante = TIMEOUT_HTTP_MS - usado;
    codigo = postUnaVez(cuerpo, restante < TIMEOUT_INTENTO_MAX_MS ? restante : TIMEOUT_INTENTO_MAX_MS);
    if (codigo > 0) {
      Serial.printf("[http] %s -> HTTP %d\n", l.uuid, codigo);
    } else {
      Serial.printf("[http] %s -> error %d (%s)\n", l.uuid, codigo,
                    HTTPClient::errorToString(codigo).c_str());
    }
    if (!esperarYReintentar(codigo) || millis() - inicio + PAUSA_REINTENTO_MS >= TIMEOUT_HTTP_MS) {
      break;
    }
    delay(PAUSA_REINTENTO_MS);
  }

  if (codigo == 200 || codigo == 201) {
    return ResultadoEnvio::Aceptada;  // 200 = duplicada, ya estaba registrada.
  }
  if (codigo == 422) {
    return ResultadoEnvio::Descartar;
  }
  // Red caída (<0), 401 token inválido o rotado, 403/409 sensor desactivado,
  // 429 límite de envíos, 5xx.
  return ResultadoEnvio::Reintentar;
}

void TransporteWifiHttps::apagar() {
  WiFi.disconnect(true);
  WiFi.mode(WIFI_OFF);
}
