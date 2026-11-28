#include <Arduino.h>
#include "esp_camera.h"
#include <WiFi.h>
#include <esp_wifi.h>

// ===========================
// Select camera model in board_config.h
// ===========================
#include "board_config.h"

// ===========================
// Enter your WiFi credentials
// ===========================
#if __has_include("wifi_secrets.h")
#include "wifi_secrets.h"
#else
const char *ssid = "TrashCollector_Robot";
const char *password = "12345678";
#endif

// Permanent Static IP on TrashCollector_Robot network (Always 192.168.4.2)
IPAddress cam_IP(192, 168, 4, 2);
IPAddress gateway(192, 168, 4, 1);
IPAddress subnet(255, 255, 255, 0);

unsigned long wifiLostSince = 0;
bool reconnectAttempted = false;
const unsigned long WIFI_RECONNECT_DELAY_MS = 3000;
const unsigned long WIFI_RESTART_TIMEOUT_MS = 15000;
extern volatile int cameraCaptureFailCount;
extern volatile bool cameraStreamActive;
extern volatile unsigned long lastCameraFrameMs;
const int CAMERA_FAIL_RESTART_THRESHOLD = 2;
const unsigned long CAMERA_STREAM_STALL_MS = 8000;

void startCameraServer();
void setupLedFlash();

void setup() {
  Serial.begin(115200);
  Serial.setDebugOutput(true);
  Serial.println();

  // MUST zero-initialize camera_config_t so jpeg_buffer_size is 0 (not stack garbage!)
  camera_config_t config = {};
  config.jpeg_buffer_size = 0;
  config.sccb_i2c_port = -1;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM;
  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;
  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;
  config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM;
  config.pin_pclk = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;
  config.pin_href = HREF_GPIO_NUM;
  config.pin_sccb_sda = SIOD_GPIO_NUM;
  config.pin_sccb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM;
  config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 10000000;  // 10MHz clock is far more resistant to brushed DC motor EMI than 20MHz
  config.pixel_format = PIXFORMAT_JPEG;

  if (psramFound()) {
    Serial.printf("PSRAM found (%u bytes) - using PSRAM frame buffers\n", ESP.getPsramSize());
    config.frame_size = FRAMESIZE_VGA;
    config.fb_location = CAMERA_FB_IN_PSRAM;
    config.jpeg_quality = 14;
    config.fb_count = 2;
    config.grab_mode = CAMERA_GRAB_LATEST;
  } else {
    Serial.println("WARNING: PSRAM not enabled in Tools > PSRAM. Using QVGA in DRAM.");
    config.frame_size = FRAMESIZE_QVGA;
    config.fb_location = CAMERA_FB_IN_DRAM;
    config.jpeg_quality = 14;
    config.fb_count = 1;
    config.grab_mode = CAMERA_GRAB_WHEN_EMPTY;
  }

#if defined(CAMERA_MODEL_ESP_EYE)
  pinMode(13, INPUT_PULLUP);
  pinMode(14, INPUT_PULLUP);
#endif

  // camera init (with fallback to QVGA DRAM if needed)
  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK && config.fb_location == CAMERA_FB_IN_PSRAM) {
    Serial.println("Retrying camera init with QVGA in DRAM...");
    esp_camera_deinit();
    config.frame_size = FRAMESIZE_QVGA;
    config.fb_location = CAMERA_FB_IN_DRAM;
    config.jpeg_quality = 14;
    config.fb_count = 1;
    config.grab_mode = CAMERA_GRAB_WHEN_EMPTY;
    err = esp_camera_init(&config);
  }

  if (err != ESP_OK) {
    Serial.printf("Camera init failed with error 0x%x\n", err);
  } else {
    sensor_t *s = esp_camera_sensor_get();
    if (s->id.PID == OV3660_PID) {
      s->set_vflip(s, 1);
      s->set_brightness(s, 1);
      s->set_saturation(s, -2);
    }
    if (config.pixel_format == PIXFORMAT_JPEG) {
      s->set_framesize(s, FRAMESIZE_QVGA);
    }
#if defined(CAMERA_MODEL_M5STACK_WIDE) || defined(CAMERA_MODEL_M5STACK_ESP32CAM)
    s->set_vflip(s, 1);
    s->set_hmirror(s, 1);
#endif
#if defined(CAMERA_MODEL_ESP32S3_EYE)
    s->set_vflip(s, 1);
#endif
  }

#if defined(LED_GPIO_NUM)
  setupLedFlash();
#endif

  WiFi.mode(WIFI_STA);
  WiFi.persistent(false);
  WiFi.setAutoReconnect(true);
  WiFi.setSleep(false);
  esp_wifi_set_ps(WIFI_PS_NONE);
  esp_wifi_set_protocol(WIFI_IF_STA, WIFI_PROTOCOL_11B | WIFI_PROTOCOL_11G | WIFI_PROTOCOL_11N);
  esp_wifi_set_bandwidth(WIFI_IF_STA, WIFI_BW_HT20);
  WiFi.config(cam_IP, gateway, subnet);
  WiFi.begin(ssid, password);
  WiFi.setTxPower(WIFI_POWER_15dBm);

  Serial.print("Connecting to Main ESP32 Wi-Fi (TrashCollector_Robot)");
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
    attempts++;
    if (attempts % 20 == 0) {
      Serial.println("\nRetrying Wi-Fi connection to TrashCollector_Robot...");
      WiFi.disconnect(false);
      delay(200);
      WiFi.config(cam_IP, gateway, subnet);
      WiFi.begin(ssid, password);
    }
  }
  Serial.println("");
  Serial.println("Connected to Main ESP32 Wi-Fi!");

  startCameraServer();

  Serial.print("Camera Ready! Use 'http://");
  Serial.print(WiFi.localIP());
  Serial.println("' to connect");
}

void loop() {
  // Auto-recover if motor EMI or overheating stalls/deadlocks the OV2640/OV3660 sensor
  if (cameraCaptureFailCount >= CAMERA_FAIL_RESTART_THRESHOLD) {
    Serial.printf("Camera sensor stalled (%d failed frames) - restarting ESP32-S3-CAM cleanly...\n", (int)cameraCaptureFailCount);
    delay(100);
    ESP.restart();
  }
  if (cameraStreamActive && lastCameraFrameMs > 0 && (millis() - lastCameraFrameMs >= CAMERA_STREAM_STALL_MS)) {
    Serial.println("Camera stream deadlocked (>8s without a frame) - restarting ESP32-S3-CAM cleanly...");
    delay(100);
    ESP.restart();
  }

  if (WiFi.status() == WL_CONNECTED) {
    if (wifiLostSince != 0) {
      Serial.print("Wi-Fi recovered! IP: ");
      Serial.println(WiFi.localIP());
      wifiLostSince = 0;
      reconnectAttempted = false;
    }
  } else {
    unsigned long now = millis();
    if (wifiLostSince == 0) {
      wifiLostSince = now;
      reconnectAttempted = false;
      Serial.println("Wi-Fi link dropped - waiting for auto-reconnect...");
    } else if (!reconnectAttempted && now - wifiLostSince >= WIFI_RECONNECT_DELAY_MS) {
      reconnectAttempted = true;
      Serial.println("Triggering clean Wi-Fi reconnect to TrashCollector_Robot...");
      WiFi.disconnect(false);
      delay(150);
      WiFi.config(cam_IP, gateway, subnet);
      WiFi.begin(ssid, password);
    } else if (now - wifiLostSince >= WIFI_RESTART_TIMEOUT_MS) {
      Serial.println("Wi-Fi lost >15s - restarting ESP32-S3-CAM cleanly...");
      delay(100);
      ESP.restart();
    }
  }
  delay(1000);
}
