// CSV sin cabecera: seq, protocolo, latencia_ms, rssi_dbm, exito.
#include <Arduino.h>
#include <WiFi.h>
#include <Wire.h>
#include <BH1750.h>
#include <DHT.h>
#include <ArduinoJson.h>
#include <math.h>
#include <freertos/FreeRTOS.h>
#include <freertos/task.h>
#include <freertos/queue.h>
#include <freertos/semphr.h>
#include <PubSubClient.h> // 2.8
#define DHTPIN 25
#define DHTTYPE DHT22
#define DSM_PIN 35
#define I2C_SDA 27
#define I2C_SCL 26

const char* ssid = "OPPO A6x 5G a5im";
const char* password = "yfrh3479";
const char* mqtt_server = "10.64.117.154";
const uint16_t mqtt_port = 1883;
const char* mqtt_topic = "telecom/lab1/ESP32_01/telemetry";
DHT dht(DHTPIN, DHTTYPE);
BH1750 luz;
bool luzLista = false;
volatile unsigned long lowPulseOccupancy = 0;
volatile unsigned long lastChangeTime = 0;
volatile bool pinState = HIGH;
unsigned long inicioPolvo = 0;
unsigned long ultimoEnvio = 0;
const unsigned long tiempoPolvo = 30000; // 30 segundos obligatorios para el sensor
const unsigned long tiempoRed = 1000;    // 5 segundos para el envio de red
float polvoGlobal = 0.0;
unsigned long seq = 0;

// Cola acotada: nunca esperar a la red desde la tarea de sensores
struct Envio {
  unsigned long seq;
  int32_t rssi;
  char payload[512];
};
QueueHandle_t cola = nullptr;
SemaphoreHandle_t serialMutex = nullptr;
bool redLista = false;
// Prototipo explicito para el tipo definido arriba en Arduino IDE
void enviar(const Envio& dato);

void IRAM_ATTR dsmISR() {
  unsigned long now = micros();
  bool nivelActual = digitalRead(DSM_PIN);

  if (pinState == HIGH && nivelActual == LOW) {
    // acaba de empezar un pulso LOW
    lastChangeTime = now;
  }
  else if (pinState == LOW && nivelActual == HIGH) {
    // el pulso LOW termino, sumamos su duracion
    lowPulseOccupancy += (now - lastChangeTime);
  }
  pinState = nivelActual;
}

void imprimirFila(unsigned long numero, long latencia, int32_t rssi, bool exito) {
  if (serialMutex) xSemaphoreTake(serialMutex, portMAX_DELAY);
  Serial.printf("%lu, MQTT, %ld, %ld, %d\n",
                numero, latencia, (long)rssi, exito ? 1 : 0);
  if (serialMutex) xSemaphoreGive(serialMutex);
}

void mantenerWiFi() {
  static unsigned long ultimoIntento = 0;
  const unsigned long ahora = millis();
  if (WiFi.status() != WL_CONNECTED && ahora - ultimoIntento >= 10000UL) {
    ultimoIntento = ahora;
    WiFi.reconnect(); // Solicitud asincrona; sin while de espera.
  }
}

// connect() puede esperar el CONNACK; se ejecuta solo en tareaRed.
// Preferir IP local del broker para no depender del DNS del laboratorio.
WiFiClient socketMQTT;
PubSubClient client(socketMQTT);
bool bufferMQTTListo = false;
char mqttClientId[40];

void mantenerMQTT() {
  static unsigned long ultimoIntento = 0;
  static bool primerIntento = true;
  if (WiFi.status() != WL_CONNECTED) {
    socketMQTT.stop();
    return;
  }
  if (client.connected()) {
    client.loop(); // Atender keepalive continuamente, independiente del intervalo de envio.
    return;
  }
  const unsigned long ahora = millis();
  if (bufferMQTTListo && (primerIntento || ahora - ultimoIntento >= 5000UL)) {
    primerIntento = false;
    ultimoIntento = ahora;
    client.connect(mqttClientId); // Puerto 1883, sin credenciales ni TLS.
  }
}

// PubSubClient publica QoS 0: true NO equivale a ACK del broker.
// La latencia excluye connect() y mide solo la llamada publish().
void enviar(const Envio& dato) {
  long latencia = -1;
  bool exito = false;
  if (WiFi.status() == WL_CONNECTED && bufferMQTTListo && client.connected()) {
    const unsigned long comienzo = millis();
    const bool enviado = client.publish(mqtt_topic, dato.payload, false);
    if (enviado) {
      latencia = (long)(millis() - comienzo);
      exito = true;
    }
  }
  imprimirFila(dato.seq, latencia, dato.rssi, exito);
}

void tareaRed(void*) {
  client.setServer(mqtt_server, mqtt_port);
  client.setSocketTimeout(3); // Segundos; limite de espera MQTT.
  client.setKeepAlive(15);
  bufferMQTTListo = client.setBufferSize(1024); // JSON + topic + cabecera.
  const uint64_t chipId = ESP.getEfuseMac();
  snprintf(mqttClientId, sizeof(mqttClientId), "ESP32_Arequipa_%04lX%08lX",
           (unsigned long)(chipId >> 32), (unsigned long)(chipId & 0xFFFFFFFFULL));
  for (;;) {
    mantenerWiFi();
    mantenerMQTT();
    Envio dato;
    if (xQueueReceive(cola, &dato, pdMS_TO_TICKS(10)) == pdTRUE) {
      enviar(dato); // Un solo intento por seq; sin reenvios ni duplicados.
    }
  }
}

void setup() {
  Serial.begin(115200);
  dht.begin();
  Wire.begin(I2C_SDA, I2C_SCL);
  luzLista = luz.begin();
  pinMode(DSM_PIN, INPUT);
  attachInterrupt(digitalPinToInterrupt(DSM_PIN), dsmISR, CHANGE);
  inicioPolvo = millis();
  ultimoEnvio = inicioPolvo;

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(ssid, password); // No esperar indefinidamente a conectarse.
  serialMutex = xSemaphoreCreateMutex();
  cola = xQueueCreate(2, sizeof(Envio));
  if (cola && serialMutex) {
    redLista = xTaskCreate(tareaRed, "red", 8192, nullptr, 1, nullptr) == pdPASS;
  }
}

void loop() {
  const unsigned long ahora = millis();

  // Tarea 1: cerrar una ventana de polvo de 30 s, sin esperar a la red.
  // Ejecutar primero: si ambos plazos coinciden, el paquete usa polvo actualizado.
  if (ahora - inicioPolvo >= tiempoPolvo) {
    noInterrupts();
    const unsigned long corteMicros = micros();
    unsigned long lecturaLow = lowPulseOccupancy;
    // Repartir un pulso LOW que cruza el limite entre ambas ventanas.
    // La ISR mantiene su logica original; la siguiente subida solo suma el resto.
    if (pinState == LOW) {
      lecturaLow += corteMicros - lastChangeTime;
    }
    lowPulseOccupancy = 0;
    lastChangeTime = corteMicros;
    interrupts();
    inicioPolvo = ahora;

    float ratio = lecturaLow / (tiempoPolvo * 10.0);
    polvoGlobal = 1.1*pow(ratio,3)
                - 3.8*pow(ratio,2)
                + 520*ratio
                + 0.62;
  }

  // Tarea 2: leer DHT22/BH1750 y encolar una muestra cada 5 s.
  // Hasta completar la primera ventana de polvo, particulas vale 0.0.
  if (ahora - ultimoEnvio >= tiempoRed) {
    ultimoEnvio = ahora;
    float temp = dht.readTemperature();
    float hum = dht.readHumidity();
    float lux = luzLista ? luz.readLightLevel() : NAN;
    Envio dato{};
    dato.seq = ++seq;
    dato.rssi = WiFi.RSSI(); // Mismo valor en contrato y fila; sin WiFi no es valido.

    JsonDocument doc;
    doc["node_id"] = "ESP32_Arequipa";
    doc["seq"] = dato.seq;
    doc["timestamp"] = 0;
    if (isfinite(temp)) doc["temperatura"] = temp;
    else doc["temperatura"] = nullptr;
    if (isfinite(hum)) doc["humedad"] = hum;
    else doc["humedad"] = nullptr;
    if (isfinite(lux) && lux >= 0) doc["luz"] = lux;
    else doc["luz"] = nullptr;
    doc["particulas"] = polvoGlobal;
    doc["rssi"] = dato.rssi;
    doc["uptime_ms"] = millis();

    if (doc.overflowed() || measureJson(doc) >= sizeof(dato.payload)) {
      imprimirFila(dato.seq, -1, dato.rssi, false);
      return;
    }
    serializeJson(doc, dato.payload, sizeof(dato.payload));
    if (!redLista || xQueueSend(cola, &dato, 0) != pdTRUE) {
      // Cola llena/fallo de recursos: registrar fallo, nunca bloquear el muestreo.
      imprimirFila(dato.seq, -1, dato.rssi, false);
    }
  }
  delay(1); // Ceder CPU a FreeRTOS sin esperar por sensores o red.
}
