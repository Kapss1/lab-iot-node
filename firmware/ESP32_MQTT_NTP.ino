// ESP32 clasico. Arduino-ESP32 3.x, ArduinoJson 7.x, PubSubClient 2.8.
// CSV: seq, protocolo, latencia_publish_ms, rssi_dbm, exito_local.
#include <Arduino.h>
#include <WiFi.h>
#include <Wire.h>
#include <BH1750.h>
#include <DHT.h>
#include <ArduinoJson.h>
#include <PubSubClient.h>
#include <Preferences.h>
#include <time.h>
#include <esp_sntp.h>
#include <math.h>
#include <freertos/FreeRTOS.h>
#include <freertos/task.h>
#include <freertos/queue.h>
#include <freertos/semphr.h>
#include <freertos/event_groups.h>
#include "secrets.h" // Copiar secrets.example.h y completar.

#define DHTPIN 25
#define DHTTYPE DHT22
#define DSM_PIN 35
#define I2C_SDA 27
#define I2C_SCL 26

constexpr uint32_t TIEMPO_POLVO_MS = 30000;
constexpr uint32_t TIEMPO_RED_MS = 5000; // DHT22: no leer cada 1 s.
constexpr uint32_t SEQ_MAX = 2147483647UL; // INT firmado del DDL exacto.
constexpr uint32_t SEQ_BLOCK = 1024;
constexpr int64_t EPOCH_MIN = 1704067200LL; // 2024-01-01 UTC.
constexpr int64_t EPOCH_MAX = 2147483647LL; // TIMESTAMP MySQL.
constexpr EventBits_t NTP_OK = BIT0;

DHT dht(DHTPIN, DHTTYPE);
BH1750 luz;
bool luzLista = false;
Preferences nvs;
bool nvsLista = false;
uint32_t siguienteSeq = 0;
uint32_t reservadoHasta = 0;

// Proteccion compartida entre ISR y loop en el ESP32 multinucleo.
portMUX_TYPE polvoMux = portMUX_INITIALIZER_UNLOCKED;
volatile uint32_t lowPulseOccupancy = 0;
volatile uint32_t lastChangeTime = 0;
volatile bool pinState = HIGH;
uint32_t inicioPolvoUs = 0;
uint32_t ultimoEnvio = 0;
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

void IRAM_ATTR dsmISR() {
  portENTER_CRITICAL_ISR(&polvoMux);
  const uint32_t ahoraUs = micros();
  const bool nivel = digitalRead(DSM_PIN);
  if (pinState == HIGH && nivel == LOW) lastChangeTime = ahoraUs;
  else if (pinState == LOW && nivel == HIGH) lowPulseOccupancy += ahoraUs - lastChangeTime;
  pinState = nivel;
  portEXIT_CRITICAL_ISR(&polvoMux);
}

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
    // Asincrono: los sensores siguen ejecutandose mientras SNTP resuelve la hora.
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
    client.connect(mqttClientId, NODE_ID, MQTT_PASSWORD);
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
  dht.begin();
  Wire.begin(I2C_SDA, I2C_SCL);
  luzLista = luz.begin();
  pinMode(DSM_PIN, INPUT);
  portENTER_CRITICAL(&polvoMux);
  pinState = digitalRead(DSM_PIN);
  inicioPolvoUs = lastChangeTime = micros();
  portEXIT_CRITICAL(&polvoMux);
  attachInterrupt(digitalPinToInterrupt(DSM_PIN), dsmISR, CHANGE);
  ultimoEnvio = millis();
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
  // Ventana de polvo independiente de WiFi/NTP. Diferencias unsigned toleran wrap.
  if ((uint32_t)(micros() - inicioPolvoUs) >= TIEMPO_POLVO_MS * 1000UL) {
    portENTER_CRITICAL(&polvoMux);
    const uint32_t corteUs = micros();
    const uint32_t duracionUs = corteUs - inicioPolvoUs;
    uint32_t lecturaLow = lowPulseOccupancy;
    if (pinState == LOW) lecturaLow += corteUs - lastChangeTime;
    lowPulseOccupancy = 0;
    lastChangeTime = corteUs;
    inicioPolvoUs = corteUs;
    portEXIT_CRITICAL(&polvoMux);
    // Porcentaje LOW respecto de la ventana REAL, no de una duracion supuesta.
    const float ratio = fminf(100.0f, 100.0f * (float)lecturaLow / (float)duracionUs);
    // Se conserva la aproximacion empirica del codigo original.
    // No representa PM2.5 ni ug/m3 calibrados.
    polvoGlobal = 1.1f * ratio * ratio * ratio - 3.8f * ratio * ratio + 520.0f * ratio + 0.62f;
  }

  if (ahora - ultimoEnvio >= TIEMPO_RED_MS) {
    ultimoEnvio = ahora;
    const float temp = dht.readTemperature();
    const float hum = dht.readHumidity();
    const float lux = luzLista ? luz.readLightLevel() : NAN;
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
    if (isfinite(temp)) value["temperatura"] = temp; else value["temperatura"] = nullptr;
    if (isfinite(hum)) value["humedad"] = hum; else value["humedad"] = nullptr;
    if (isfinite(lux) && lux >= 0) value["lux"] = lux; else value["lux"] = nullptr;
    if (isfinite(polvoGlobal)) value["particulas"] = polvoGlobal; else value["particulas"] = nullptr;
    if (doc.overflowed() || measureJson(doc) >= sizeof(dato.payload)) {
      imprimirFila(dato.seq, -1, dato.rssi, false);
    } else {
      serializeJson(doc, dato.payload, sizeof(dato.payload));
      if (xQueueSend(cola, &dato, 0) != pdTRUE) imprimirFila(dato.seq, -1, dato.rssi, false);
    }
  }
  delay(1);
}
