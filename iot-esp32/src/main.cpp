// Nodo sensor de llenado EcoRuta (ESP32 + HC-SR04).
//
// Cada ciclo: mide la distancia (mediana de 5 muestras) y la batería, guarda la
// lectura, se conecta al Wi-Fi, envía las pendientes de la más antigua a la
// más nueva y duerme 5 min. Sin red, las lecturas quedan en la memoria no
// volátil (NVS) y se reenvían en el siguiente ciclo con conexión. El servidor
// descarta duplicados por `lectura_uuid`, así que reenviar es seguro.
//
// El nodo no corrige distancias: la calibración vive en el servidor.

#include <Arduino.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <esp_random.h>
#include <esp_sleep.h>
#include <time.h>

#include "config.h"

#if __has_include("secrets.h")
#include "secrets.h"
#else
#error "Falta include/secrets.h: copia include/secrets.example.h y completa los valores."
#endif

#include "raices_ca.h"

#ifndef INTERVALO_SEG
#define INTERVALO_SEG 300
#endif

namespace {

// Una lectura tal como se guarda y se envía. `medidoEn` = 0 si el reloj no
// estaba en hora al medir (el servidor usa entonces la hora de recepción).
struct Lectura {
  float distanciaCm;  // NAN si ninguna muestra tuvo eco.
  float bateriaV;     // NAN si no se pudo leer.
  uint32_t medidoEn;  // Segundos Unix UTC.
  char uuid[37];
};

struct Pendientes {
  uint8_t cantidad;
  Lectura items[MAX_PENDIENTES];  // De la más antigua a la más nueva.
};

// Resultado de un envío: qué hacer con la lectura y con el resto de la cola.
enum class Envio { Aceptada, Descartar, Reintentar };

constexpr const char* NVS_ESPACIO = "ecoruta";
constexpr const char* NVS_CLAVE = "pendientes";

Pendientes cola;

// ---------- Medición ----------

float medirMuestraCm() {
  digitalWrite(PIN_TRIG, LOW);
  delayMicroseconds(4);
  digitalWrite(PIN_TRIG, HIGH);
  delayMicroseconds(10);
  digitalWrite(PIN_TRIG, LOW);
  const unsigned long us = pulseIn(PIN_ECHO, HIGH, TIMEOUT_ECO_US);
  return us == 0 ? NAN : us * VELOCIDAD_SONIDO_CM_US / 2.0f;
}

// Mediana de las muestras con eco; NAN si ninguna lo tuvo.
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
  // Inserción: a lo sumo 5 elementos.
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

// ---------- Utilidades ----------

bool relojEnHora() { return time(nullptr) >= static_cast<time_t>(EPOCH_VALIDO_MIN); }

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

void formatearIso(uint32_t epoch, char* destino, size_t largo) {
  const time_t t = epoch;
  struct tm utc;
  gmtime_r(&t, &utc);
  strftime(destino, largo, "%Y-%m-%dT%H:%M:%SZ", &utc);
}

// ---------- Cola persistente ----------

void cargarCola() {
  Preferences prefs;
  cola.cantidad = 0;
  if (!prefs.begin(NVS_ESPACIO, true)) {
    return;  // Aún no existe: primera vez.
  }
  if (prefs.getBytesLength(NVS_CLAVE) == sizeof(Pendientes)) {
    prefs.getBytes(NVS_CLAVE, &cola, sizeof(Pendientes));
    if (cola.cantidad > MAX_PENDIENTES) {
      cola.cantidad = 0;  // Datos corruptos.
    }
  }
  prefs.end();
}

void guardarCola() {
  Preferences prefs;
  if (!prefs.begin(NVS_ESPACIO, false)) {
    Serial.println("[nvs] no se pudo abrir: las pendientes se pierden");
    return;
  }
  if (cola.cantidad == 0) {
    prefs.remove(NVS_CLAVE);
  } else {
    prefs.putBytes(NVS_CLAVE, &cola, sizeof(Pendientes));
  }
  prefs.end();
}

void agregarACola(const Lectura& l) {
  if (cola.cantidad == MAX_PENDIENTES) {
    memmove(&cola.items[0], &cola.items[1], sizeof(Lectura) * (MAX_PENDIENTES - 1));
    cola.cantidad--;
    Serial.println("[cola] llena: se descarta la lectura más antigua");
  }
  cola.items[cola.cantidad++] = l;
}

void quitarPrimeras(uint8_t n) {
  if (n == 0) {
    return;
  }
  memmove(&cola.items[0], &cola.items[n], sizeof(Lectura) * (cola.cantidad - n));
  cola.cantidad -= n;
}

// ---------- Red ----------

bool conectarWifi() {
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  const uint32_t inicio = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - inicio < TIMEOUT_WIFI_MS) {
    delay(200);
  }
  return WiFi.status() == WL_CONNECTED;
}

// Pone en hora el reloj (se conserva durante el deep sleep).
void sincronizarHora() {
  configTime(0, 0, "pool.ntp.org", "time.google.com");
  const uint32_t inicio = millis();
  while (!relojEnHora() && millis() - inicio < TIMEOUT_NTP_MS) {
    delay(100);
  }
}

void armarJson(const Lectura& l, char* destino, size_t largo) {
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
    char iso[24];
    formatearIso(l.medidoEn, iso, sizeof(iso));
    snprintf(medido, sizeof(medido), ",\"medido_en\":\"%s\"", iso);
  }
  snprintf(destino, largo,
           "{\"distancia_cm\":%s,\"bateria_voltaje\":%s,\"lectura_uuid\":\"%s\"%s}",
           distancia, bateria, l.uuid, medido);
}

// Un intento de POST que espera a lo sumo `timeoutMs`. Devuelve el código
// HTTP o un error negativo de HTTPClient.
int postUnaVez(const char* cuerpo, uint32_t timeoutMs) {
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

bool esperarYReintentar(int codigo) {
  return codigo < 0 || codigo == 502 || codigo == 503 || codigo == 504;
}

Envio enviar(const Lectura& l) {
  char cuerpo[256];
  armarJson(l, cuerpo, sizeof(cuerpo));

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
    return Envio::Aceptada;  // 200 = duplicada, ya estaba registrada.
  }
  if (codigo == 422) {
    return Envio::Descartar;  // El servidor nunca la aceptará: no bloquear la cola.
  }
  // Red caída (<0), 401 token inválido o rotado, 403/409 sensor desactivado,
  // 429 límite de envíos, 5xx: se conserva todo y se reintenta el próximo ciclo.
  return Envio::Reintentar;
}

void enviarPendientes() {
  uint8_t enviadas = 0;
  while (enviadas < cola.cantidad) {
    const Envio r = enviar(cola.items[enviadas]);
    if (r == Envio::Reintentar) {
      break;
    }
    if (r == Envio::Descartar) {
      Serial.printf("[cola] descartada %s (rechazada por el servidor)\n", cola.items[enviadas].uuid);
    }
    enviadas++;
  }
  quitarPrimeras(enviadas);
}

void dormir(uint32_t inicioCicloMs) {
  WiFi.disconnect(true);
  WiFi.mode(WIFI_OFF);
  const uint64_t transcurridoUs = static_cast<uint64_t>(millis() - inicioCicloMs) * 1000ULL;
  const uint64_t intervaloUs = static_cast<uint64_t>(INTERVALO_SEG) * 1000000ULL;
  const uint64_t esperaUs = transcurridoUs < intervaloUs ? intervaloUs - transcurridoUs : 1000000ULL;
  Serial.printf("[ciclo] durmiendo %llu s\n", esperaUs / 1000000ULL);
  Serial.flush();
  esp_sleep_enable_timer_wakeup(esperaUs);
  esp_deep_sleep_start();
}

}  // namespace

void setup() {
  const uint32_t inicioCiclo = millis();
  Serial.begin(115200);
#ifdef TEST_ONLY
  Serial.println("[aviso] TEST_ONLY: HTTPS sin validar el certificado");
#endif
  pinMode(PIN_TRIG, OUTPUT);
  digitalWrite(PIN_TRIG, LOW);
  pinMode(PIN_ECHO, INPUT);
  analogSetPinAttenuation(PIN_BATERIA, ADC_11db);

  // Se mide antes de encender el Wi-Fi (menos ruido en el ADC).
  Lectura nueva;
  nueva.distanciaCm = medirDistanciaCm();
  nueva.bateriaV = medirBateriaV();
  nueva.medidoEn = relojEnHora() ? static_cast<uint32_t>(time(nullptr)) : 0;
  const uint32_t medidaEnMs = millis();
  generarUuid(nueva.uuid);
  if (isnan(nueva.distanciaCm)) {
    Serial.printf("[medicion] distancia: sin eco · bateria=%.2f V\n", nueva.bateriaV);
  } else {
    Serial.printf("[medicion] distancia=%.1f cm · bateria=%.2f V\n", nueva.distanciaCm, nueva.bateriaV);
  }

  cargarCola();
  const uint8_t habiaPendientes = cola.cantidad;

  if (conectarWifi()) {
    Serial.printf("[wifi] conectado a %s · IP %s · RSSI %d dBm\n", WIFI_SSID,
                  WiFi.localIP().toString().c_str(), WiFi.RSSI());
    sincronizarHora();
    if (!relojEnHora()) {
      Serial.println("[ntp] sin hora: HTTPS no podrá validar el certificado");
    }
    // Primer arranque sin hora: se fecha la lectura recién tomada restando lo
    // que tardó la conexión. Las guardadas sin hora se envían sin `medido_en`.
    if (nueva.medidoEn == 0 && relojEnHora()) {
      nueva.medidoEn = static_cast<uint32_t>(time(nullptr)) - (millis() - medidaEnMs) / 1000;
    }
    agregarACola(nueva);
    enviarPendientes();
  } else {
    Serial.println("[wifi] sin conexión: la lectura queda guardada");
    agregarACola(nueva);
  }

  // Solo se escribe en la flash si hay o había pendientes (cuida su desgaste).
  if (habiaPendientes > 0 || cola.cantidad > 0) {
    guardarCola();
  }
  Serial.printf("[cola] lecturas pendientes: %u\n", cola.cantidad);
  dormir(inicioCiclo);
}

void loop() {
  // No se usa: cada ciclo termina en deep sleep y el ESP32 reinicia en setup().
}
