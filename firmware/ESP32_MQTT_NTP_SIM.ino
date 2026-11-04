// SIMULADOR de ESP32_MQTT_NTP.ino. Sin sensores fisicos.
// ESP32 clasico. Arduino-ESP32 3.x, ArduinoJson 7.x, PubSubClient 2.8.
// Conserva del original: WiFi, NTP (UTC, epoch en segundos), MQTT (topic, clientId,
// usuario/clave), JSON (node_id, ts, rssi, seq, value{...}), seq persistente en NVS
// por bloques, tarea FreeRTOS "red" + cola, reconexiones sin bloqueo y CSV por Serial.
// CSV: seq, protocolo, latencia_publish_ms, rssi_dbm, exito_local.
// Solo cambia el origen de los datos: temperatura, humedad, lux y particulas simulados.
#include <Arduino.h>
#include <WiFi.h>
#include <ArduinoJson.h>
#include <PubSubClient.h>
#include <Preferences.h>
#include <time.h>
#include <esp_sntp.h>
#include <esp_random.h>
#include <math.h>
#include <freertos/FreeRTOS.h>
#include <freertos/task.h>
#include <freertos/queue.h>
#include <freertos/semphr.h>
#include <freertos/event_groups.h>
#include "secrets.h" // Mismo secrets.h del original: WIFI_SSID, WIFI_PASSWORD, MQTT_HOST, MQTT_PORT, NODE_ID, MQTT_PASSWORD.

// ===================== Parametros de temporizacion (igual que el original) =====================
constexpr uint32_t TIEMPO_POLVO_MS = 30000; // El original refresca "particulas" cada 30 s.
constexpr uint32_t TIEMPO_RED_MS = 5000;    // Adquisicion + envio cada 5 s.
constexpr uint32_t SEQ_MAX = 2147483647UL;  // INT firmado del DDL exacto.
constexpr uint32_t SEQ_BLOCK = 1024;
constexpr int64_t EPOCH_MIN = 1704067200LL; // 2024-01-01 UTC.
constexpr int64_t EPOCH_MAX = 2147483647LL; // TIMESTAMP MySQL.
constexpr EventBits_t NTP_OK = BIT0;

// ===================== Rangos de simulacion (modificar aqui) =====================
constexpr float TEMP_MIN = 18.0f, TEMP_MAX = 32.0f, TEMP_PASO = 0.30f;  // grados C
constexpr float HUM_MIN = 35.0f, HUM_MAX = 85.0f, HUM_PASO = 1.00f;     // % HR
constexpr float LUX_MIN = 50.0f, LUX_MAX = 1000.0f, LUX_PASO = 40.0f;   // lux
// Particulas: se simula el % de tiempo en LOW del DSM501A (ratio, 0-100) y luego se aplica
// la MISMA formula empirica del original. Aire limpio ~0.5 %, aire cargado ~5 %.
constexpr float RATIO_MIN = 0.5f, RATIO_MAX = 5.0f, RATIO_PASO = 0.35f;

Preferences nvs;
bool nvsLista = false;
uint32_t siguienteSeq = 0;
uint32_t reservadoHasta = 0;

uint32_t ultimoEnvio = 0;
uint32_t ultimoPolvo = 0;

// Estado de la simulacion (caminata aleatoria acotada).
float tempSim, humSim, luxSim, ratioSim;
float polvoGlobal = NAN;

struct Envio {
  uint32_t seq;
  int32_t rssi;
  char payload[512];
};
void enviar(const Envio& dato); // Prototipo para Arduino IDE.
QueueHandle_t cola = nullptr;
SemaphoreHandle_t serialMutex = nullptr;
EventGroupHandle_t estadoRed = nullptr;
bool redLista = false;
WiFiClient socketMQTT;
PubSubClient client(socketMQTT);
bool bufferMQTTListo = false;
char mqttClientId[64];
char mqttTopic[96];

// ===================== Simulacion de sensores =====================
// Numero aleatorio uniforme en [0,1] con el RNG por hardware del ESP32.
float aleatorio01() { return (float)(esp_random() % 100001UL) / 100000.0f; }

float limitar(float v, float mn, float mx) { return v < mn ? mn : (v > mx ? mx : v); }

float redondear(float v, int decimales) {
  float f = 1.0f;
  for (int i = 0; i < decimales; i++) f *= 10.0f;
  return roundf(v * f) / f;
}

float valorInicial(float mn, float mx) { return mn + aleatorio01() * (mx - mn); }

// Un paso de caminata aleatoria: variacion pequena en [-paso, +paso], acotada a [mn, mx].
float caminata(float actual, float mn, float mx, float paso) {
  return limitar(actual + (aleatorio01() * 2.0f - 1.0f) * paso, mn, mx);
}

float simularTemperatura() {
  tempSim = caminata(tempSim, TEMP_MIN, TEMP_MAX, TEMP_PASO);
  return redondear(tempSim, 1);
}

float simularHumedad() {
  humSim = caminata(humSim, HUM_MIN, HUM_MAX, HUM_PASO);
  return redondear(humSim, 1);
}

float simularLux() {
  luxSim = caminata(luxSim, LUX_MIN, LUX_MAX, LUX_PASO);
  return redondear(luxSim, 1);
}

float simularParticulas() {
  ratioSim = caminata(ratioSim, RATIO_MIN, RATIO_MAX, RATIO_PASO);
  const float ratio = ratioSim;
  // Misma aproximacion empirica del original. No representa PM2.5 ni ug/m3 calibrados.
  const float valor = 1.1f * ratio * ratio * ratio - 3.8f * ratio * ratio + 520.0f * ratio + 0.62f;
  return redondear(valor, 2);
}

void iniciarSimulacion() {
  tempSim = valorInicial(TEMP_MIN, TEMP_MAX);
  humSim = valorInicial(HUM_MIN, HUM_MAX);
  luxSim = valorInicial(LUX_MIN, LUX_MAX);
  ratioSim = valorInicial(RATIO_MIN, RATIO_MAX);
  polvoGlobal = simularParticulas(); // Evita "null" al arrancar (el original esperaba 30 s).
}

// ===================== Serial / NTP / secuencia =====================
void imprimirFila(uint32_t numero, long latencia, int32_t rssi, bool exito) {
  if (serialMutex) xSemaphoreTake(serialMutex, portMAX_DELAY);
  Serial.printf("%lu, MQTT, %ld, %ld, %d\n", (unsigned long)numero, latencia, (long)rssi, exito ? 1 : 0);
  if (serialMutex) xSemaphoreGive(serialMutex);
}

void horaSincronizada(struct timeval* ajuste) {
  // Callback SNTP (contexto de tarea, no ISR). Marca una sincronizacion real y valida.
  if (!estadoRed) return;
  if (ajuste && (int64_t)ajuste->tv_sec >= EPOCH_MIN && (int64_t)ajuste->tv_sec <= EPOCH_MAX) {
    xEventGroupSetBits(estadoRed, NTP_OK);
  } else {
    xEventGroupClearBits(estadoRed, NTP_OK);
  }
}

bool obtenerEpoch(time_t& epoch) {
  if (!estadoRed || !(xEventGroupGetBits(estadoRed) & NTP_OK)) return false;
  epoch = time(nullptr);
  return (int64_t)epoch >= EPOCH_MIN && (int64_t)epoch <= EPOCH_MAX;
}

void mantenerNTP() {
  static bool iniciado = false;
  static uint32_t ultimoIntento = 0;
  if (WiFi.status() != WL_CONNECTED) return;
  const uint32_t ahora = millis();
  if (!iniciado || (!(xEventGroupGetBits(estadoRed) & NTP_OK) && ahora - ultimoIntento >= 60000UL)) {
    iniciado = true;
    ultimoIntento = ahora;
    // UTC: el epoch nunca debe ajustarse restando 5 horas por Lima.
    // Asincrono: la simulacion sigue ejecutandose mientras SNTP resuelve la hora.
    configTime(0, 0, "pool.ntp.org", "time.nist.gov", "time.google.com");
  }
}

bool nuevaSecuencia(uint32_t& resultado) {
  if (!nvsLista || siguienteSeq == 0 || siguienteSeq > SEQ_MAX) return false;
  if (siguienteSeq > reservadoHasta) {
    const uint32_t restante = SEQ_MAX - reservadoHasta;
    const uint32_t nuevoLimite = reservadoHasta + (restante < SEQ_BLOCK ? restante : SEQ_BLOCK);
    // Persistir ANTES de utilizar el bloque: un corte de energia solo crea huecos.
    if (nvs.putUInt("seq_high", nuevoLimite) != sizeof(uint32_t)) return false;
    reservadoHasta = nuevoLimite;
  }
  resultado = siguienteSeq++;
  return true;
}

// ===================== Conectividad =====================
void mantenerWiFi() {
  static uint32_t ultimoIntento = 0;
  const uint32_t ahora = millis();
  if (WiFi.status() != WL_CONNECTED && ahora - ultimoIntento >= 10000UL) {
    ultimoIntento = ahora;
    WiFi.reconnect();
  }
}

void mantenerMQTT() {
  static uint32_t ultimoIntento = 0;
  static bool primerIntento = true;
  if (WiFi.status() != WL_CONNECTED) {
    socketMQTT.stop();
    return;
  }
  if (client.connected()) { client.loop(); return; }
  const uint32_t ahora = millis();
  time_t epoch;
  if (!obtenerEpoch(epoch)) return;
  if (bufferMQTTListo && (primerIntento || ahora - ultimoIntento >= 5000UL)) {
    primerIntento = false;
    ultimoIntento = ahora;
    // El broker debe limitar a este username a su propio topic (ACL adjunta).
    bool conectado = client.connect(
      mqttClientId,
    NODE_ID,
    MQTT_PASSWORD
);

if (serialMutex) {
    xSemaphoreTake(serialMutex, portMAX_DELAY);
}

Serial.printf(
    "[DEBUG MQTT] Conectado: %d | Estado: %d | WiFi: %d\n",
    conectado ? 1 : 0,
    client.state(),
    WiFi.status()
);

if (serialMutex) {
    xSemaphoreGive(serialMutex);
}
  }
}

void enviar(const Envio& dato) {
  long latencia = -1;
  bool exito = false;
  time_t epoch;
  if (obtenerEpoch(epoch) && WiFi.status() == WL_CONNECTED && bufferMQTTListo && client.connected()) {
    const uint32_t comienzo = millis();
    // QoS 0. true significa escritura local correcta; no es ACK del backend.
    exito = client.publish(mqttTopic, dato.payload, false);
    if (exito) latencia = (long)(millis() - comienzo);
  }
  imprimirFila(dato.seq, latencia, dato.rssi, exito);
}

void tareaRed(void*) {
  client.setServer(MQTT_HOST, MQTT_PORT);
  client.setSocketTimeout(3);
  client.setKeepAlive(15);
  bufferMQTTListo = client.setBufferSize(1024);
  const uint64_t chipId = ESP.getEfuseMac();
  snprintf(mqttClientId, sizeof(mqttClientId), "%s_%04lX%08lX", NODE_ID,
           (unsigned long)(chipId >> 32), (unsigned long)(chipId & 0xFFFFFFFFULL));
  snprintf(mqttTopic, sizeof(mqttTopic), "telecom/lab1/%s/telemetry", NODE_ID);
  for (;;) {
    mantenerWiFi();
    mantenerNTP();
    mantenerMQTT();
    Envio dato;
    if (xQueueReceive(cola, &dato, pdMS_TO_TICKS(10)) == pdTRUE) enviar(dato);
  }
}

// ===================== Arduino =====================
void setup() {
  Serial.begin(115200);
  serialMutex = xSemaphoreCreateMutex();
  cola = xQueueCreate(2, sizeof(Envio));
  estadoRed = xEventGroupCreate();
  nvsLista = nvs.begin("telemetry", false);
  if (nvsLista) {
    reservadoHasta = nvs.getUInt("seq_high", 0);
    siguienteSeq = reservadoHasta < SEQ_MAX ? reservadoHasta + 1 : 0;
  }
  iniciarSimulacion();
  ultimoEnvio = ultimoPolvo = millis();
  if (!cola || !serialMutex || !estadoRed || !nvsLista) {
    Serial.println("ERROR: recursos/NVS; no se publicara.");
    return;
  }
  sntp_set_time_sync_notification_cb(horaSincronizada);
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  redLista = xTaskCreate(tareaRed, "red", 8192, nullptr, 1, nullptr) == pdPASS;
}

void loop() {
  const uint32_t ahora = millis();
  // Equivalente a la ventana de polvo del original: nuevo valor cada 30 s.
  if (ahora - ultimoPolvo >= TIEMPO_POLVO_MS) {
    ultimoPolvo = ahora;
    polvoGlobal = simularParticulas();
  }

  if (ahora - ultimoEnvio >= TIEMPO_RED_MS) {
    ultimoEnvio = ahora;
    const float temp = simularTemperatura();
    const float hum = simularHumedad();
    const float lux = simularLux();
    time_t epoch;
    // No inventar timestamps ni registrar RSSI invalido durante desconexion.
    if (!redLista || WiFi.status() != WL_CONNECTED || !obtenerEpoch(epoch)) { delay(1); return; }
    Envio dato{};
    if (!nuevaSecuencia(dato.seq)) { Serial.println("ERROR: secuencia/NVS agotada; no se publicara."); delay(1); return; }
    dato.rssi = WiFi.RSSI();
    JsonDocument doc;
    doc["node_id"] = NODE_ID;
    doc["ts"] = (int64_t)epoch; // Unix epoch en SEGUNDOS, UTC. Nunca HH:MM.
    doc["rssi"] = dato.rssi;
    doc["seq"] = dato.seq;
    JsonObject value = doc["value"].to<JsonObject>();
    value["temperatura"] = temp;
    value["humedad"] = hum;
    value["lux"] = lux;
    value["particulas"] = polvoGlobal;
    if (doc.overflowed() || measureJson(doc) >= sizeof(dato.payload)) {
      imprimirFila(dato.seq, -1, dato.rssi, false);
    } else {
      serializeJson(doc, dato.payload, sizeof(dato.payload));
      if (xQueueSend(cola, &dato, 0) != pdTRUE) imprimirFila(dato.seq, -1, dato.rssi, false);
    }
  }
  delay(1);
}
