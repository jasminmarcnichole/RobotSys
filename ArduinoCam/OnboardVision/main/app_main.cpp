#include <cstdio>
#include <cstring>
#include <initializer_list>

#include "board_config.h"
#include "cJSON.h"
#include "coco_detect.hpp"
#include "dl_image_jpeg.hpp"
#include "esp_camera.h"
#include "esp_chip_info.h"
#include "esp_flash.h"
#include "esp_heap_caps.h"
#include "esp_log.h"
#include "esp_psram.h"
#include "esp_random.h"
#include "esp_system.h"
#include "esp_task_wdt.h"
#include "esp_timer.h"
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "mbedtls/base64.h"
#include "target_detection.hpp"
#include "esp_wifi.h"
#include "esp_event.h"
#include "nvs_flash.h"
#include "esp_netif.h"
#include "esp_http_client.h"
#include "esp_event.h"
#include "esp_wifi.h"
#include "nvs_flash.h"
#include "esp_netif.h"
#include "freertos/event_groups.h"

#if !CONFIG_IDF_TARGET_ESP32S3 || !CONFIG_COCO_DETECT_MODEL_IN_FLASH_RODATA || !CONFIG_FLASH_COCO_DETECT_YOLO11N_320_S8_V1
#error "Phase 1 requires ESP32-S3 and the 320 COCO model embedded in flash rodata."
#endif

#ifndef CONFIG_ROBOT_WIFI_SSID
#define CONFIG_ROBOT_WIFI_SSID ""
#endif
#ifndef CONFIG_ROBOT_WIFI_PASSWORD
#define CONFIG_ROBOT_WIFI_PASSWORD ""
#endif
#ifndef CONFIG_ROBOT_LARAVEL_URL
#define CONFIG_ROBOT_LARAVEL_URL "http://192.168.1.100:8000/api/vision/frames"
#endif
#ifndef CONFIG_ROBOT_VISION_TOKEN
#define CONFIG_ROBOT_VISION_TOKEN ""
#endif
#ifndef CONFIG_ROBOT_VISION_DEVICE_ID
#define CONFIG_ROBOT_VISION_DEVICE_ID "CAM-S3-001"
#endif


namespace {
constexpr char TAG[] = "robotsys_vision";
constexpr char MODEL[] = "espressif/coco_detect@0.4.2:yolo11n_320_s8_v1_s3";
constexpr float THRESHOLD = CONFIG_ROBOT_VISION_CONFIDENCE_PERCENT / 100.0f;
char boot_id[17];
static EventGroupHandle_t s_wifi_event_group;
static const int WIFI_CONNECTED_BIT = BIT0;
static bool s_wifi_connected = false;

static void wifi_event_handler(void* arg, esp_event_base_t event_base,
                                int32_t event_id, void* event_data)
{
    if (event_base == WIFI_EVENT && event_id == WIFI_EVENT_STA_START) {
        esp_wifi_connect();
    } else if (event_base == WIFI_EVENT && event_id == WIFI_EVENT_STA_DISCONNECTED) {
        s_wifi_connected = false;
        esp_wifi_connect();
    } else if (event_base == IP_EVENT && event_id == IP_EVENT_STA_GOT_IP) {
        s_wifi_connected = true;
        xEventGroupSetBits(s_wifi_event_group, WIFI_CONNECTED_BIT);
    }
}

static void wifi_init_sta(void)
{
    s_wifi_event_group = xEventGroupCreate();
    ESP_ERROR_CHECK(esp_netif_init());
    ESP_ERROR_CHECK(esp_event_loop_create_default());
    esp_netif_create_default_wifi_sta();

    wifi_init_config_t cfg = WIFI_INIT_CONFIG_DEFAULT();
    ESP_ERROR_CHECK(esp_wifi_init(&cfg));

    esp_event_handler_instance_t instance_any_id;
    esp_event_handler_instance_t instance_got_ip;
    ESP_ERROR_CHECK(esp_event_handler_instance_register(WIFI_EVENT,
                                                        ESP_EVENT_ANY_ID,
                                                        &wifi_event_handler,
                                                        NULL,
                                                        &instance_any_id));
    ESP_ERROR_CHECK(esp_event_handler_instance_register(IP_EVENT,
                                                        IP_EVENT_STA_GOT_IP,
                                                        &wifi_event_handler,
                                                        NULL,
                                                        &instance_got_ip));

    wifi_config_t wifi_config = {};
    strncpy((char *)wifi_config.sta.ssid, CONFIG_ROBOT_WIFI_SSID, sizeof(wifi_config.sta.ssid) - 1);
    strncpy((char *)wifi_config.sta.password, CONFIG_ROBOT_WIFI_PASSWORD, sizeof(wifi_config.sta.password) - 1);
    wifi_config.sta.threshold.authmode = WIFI_AUTH_WPA2_PSK;

    ESP_ERROR_CHECK(esp_wifi_set_mode(WIFI_MODE_STA));
    ESP_ERROR_CHECK(esp_wifi_set_config(WIFI_IF_STA, &wifi_config));
    ESP_ERROR_CHECK(esp_wifi_start());
}

static esp_err_t http_post_detection(const char *json_data)
{
    if (!s_wifi_connected || strlen(CONFIG_ROBOT_VISION_TOKEN) == 0) {
        return ESP_FAIL;
    }
    esp_http_client_config_t config = {};
    config.url = CONFIG_ROBOT_LARAVEL_URL;
    config.method = HTTP_METHOD_POST;
    config.timeout_ms = 5000;
    esp_http_client_handle_t client = esp_http_client_init(&config);
    if (!client) return ESP_FAIL;
    char auth[256];
    snprintf(auth, sizeof(auth), "Bearer %s", CONFIG_ROBOT_VISION_TOKEN);
    esp_http_client_set_header(client, "Authorization", auth);
    esp_http_client_set_header(client, "Content-Type", "application/json");
    esp_http_client_set_header(client, "Accept", "application/json");
    esp_http_client_set_post_field(client, json_data, strlen(json_data));
    esp_err_t err = esp_http_client_perform(client);
    esp_http_client_cleanup(client);
    return err;
}


uint64_t uptime_ms()
{
    return esp_timer_get_time() / 1000;
}

bool has_fields(const cJSON *object, std::initializer_list<const char *> fields)
{
    for (const char *name : fields) {
        if (!cJSON_GetObjectItemCaseSensitive(object, name)) {
            return false;
        }
    }
    return true;
}

bool complete_event(const cJSON *root)
{
    if (!has_fields(root, {"schema_version", "type", "status", "boot_id", "inference_source", "model",
                           "timestamp_clock", "timestamp_ms"})) {
        return false;
    }
    const char *type = cJSON_GetObjectItemCaseSensitive(root, "type")->valuestring;
    if (std::strcmp(type, "vision_error") == 0) {
        return has_fields(root, {"reason", "sequence", "valid_frame"});
    }
    if (std::strcmp(type, "vision_hardware") == 0) {
        return has_fields(root, {"flash_bytes", "psram_bytes", "idf_version"});
    }
    if (std::strcmp(type, "vision_snapshot") == 0) {
        return has_fields(root, {"sequence", "captured_at_ms", "frame_width", "frame_height",
                                "mime_type", "jpeg_bytes", "jpeg_base64"});
    }
    if (std::strcmp(type, "vision_frame") != 0) {
        return true;
    }
    if (!has_fields(root, {"detections", "valid_frame", "sequence", "captured_at_ms", "frame_width", "frame_height",
                           "target_count", "raw_detection_count", "confidence_threshold", "frame_age_ms", "inference_ms",
                           "processing_ms", "free_psram_bytes"})) {
        return false;
    }
    const cJSON *detections = cJSON_GetObjectItemCaseSensitive(root, "detections");
    const int count = cJSON_GetArraySize(detections);
    if (count != cJSON_GetObjectItemCaseSensitive(root, "target_count")->valueint ||
        std::strcmp(cJSON_GetObjectItemCaseSensitive(root, "status")->valuestring,
                    count ? "detecting" : "no_target") != 0) {
        return false;
    }
    const cJSON *item;
    cJSON_ArrayForEach(item, detections) {
        if (!has_fields(item, {"class_id", "label", "confidence", "timestamp_ms", "direction", "bbox", "center"}) ||
            !has_fields(cJSON_GetObjectItemCaseSensitive(item, "bbox"), {"x", "y", "width", "height"}) ||
            !has_fields(cJSON_GetObjectItemCaseSensitive(item, "center"), {"x", "y"})) {
            return false;
        }
    }
    return true;
}

cJSON *event(const char *type, const char *status)
{
    cJSON *root = cJSON_CreateObject();
    if (root) {
        cJSON_AddNumberToObject(root, "schema_version", 1);
        cJSON_AddStringToObject(root, "type", type);
        cJSON_AddStringToObject(root, "status", status);
        cJSON_AddStringToObject(root, "boot_id", boot_id);
        cJSON_AddStringToObject(root, "inference_source", "esp32s3_onboard");
        cJSON_AddStringToObject(root, "model", MODEL);
        cJSON_AddStringToObject(root, "timestamp_clock", "uptime_ms");
        cJSON_AddNumberToObject(root, "timestamp_ms", uptime_ms());
    }
    return root;
}

void emit(cJSON *root)
{
    if (!root) {
        ESP_LOGE(TAG, "Cannot allocate JSON event; no detection published.");
        return;
    }
    if (!complete_event(root)) {
        ESP_LOGE(TAG, "Incomplete JSON event suppressed; check available heap.");
        cJSON_Delete(root);
        return;
    }
    char *line = cJSON_PrintUnformatted(root);
    if (line) {
        std::puts(line);
        std::fflush(stdout);
        cJSON_free(line);
    } else {
        ESP_LOGE(TAG, "Cannot serialize event; no detection published.");
    }
    cJSON_Delete(root);
}

void emit_error(const char *reason, uint64_t sequence = 0)
{
    cJSON *root = event("vision_error", "error");
    if (root) {
        cJSON_AddStringToObject(root, "reason", reason);
        cJSON_AddNumberToObject(root, "sequence", sequence);
        cJSON_AddFalseToObject(root, "valid_frame");
        cJSON_AddStringToObject(root, "device_id", CONFIG_ROBOT_VISION_DEVICE_ID);
    }
    emit(root);
}

#if CONFIG_ROBOT_VISION_SERIAL_SNAPSHOT
void emit_snapshot(const camera_fb_t &frame, uint64_t sequence, uint64_t captured_at_ms)
{
    if (frame.format != PIXFORMAT_JPEG || frame.len == 0 || frame.len > 64 * 1024) {
        ESP_LOGW(TAG, "Diagnostic JPEG unavailable or too large; inference continues.");
        return;
    }
    size_t capacity = 0;
    if (mbedtls_base64_encode(nullptr, 0, &capacity, frame.buf, frame.len) != MBEDTLS_ERR_BASE64_BUFFER_TOO_SMALL) {
        ESP_LOGW(TAG, "Cannot size diagnostic JPEG encoding; inference continues.");
        return;
    }
    auto *encoded = static_cast<unsigned char *>(heap_caps_malloc(capacity, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT));
    if (!encoded) {
        ESP_LOGW(TAG, "Cannot allocate diagnostic JPEG encoding; inference continues.");
        return;
    }
    size_t encoded_length = 0;
    if (mbedtls_base64_encode(encoded, capacity, &encoded_length, frame.buf, frame.len) != 0 || encoded_length >= capacity) {
        heap_caps_free(encoded);
        ESP_LOGW(TAG, "Cannot encode diagnostic JPEG; inference continues.");
        return;
    }
    encoded[encoded_length] = '\0';
    cJSON *root = event("vision_snapshot", "captured");
    if (root) {
        cJSON_AddNumberToObject(root, "sequence", sequence);
        cJSON_AddNumberToObject(root, "captured_at_ms", captured_at_ms);
        cJSON_AddNumberToObject(root, "frame_width", frame.width);
        cJSON_AddNumberToObject(root, "frame_height", frame.height);
        cJSON_AddStringToObject(root, "mime_type", "image/jpeg");
        cJSON_AddNumberToObject(root, "jpeg_bytes", frame.len);
        cJSON_AddStringToObject(root, "jpeg_base64", reinterpret_cast<const char *>(encoded));
    }
    heap_caps_free(encoded);
    emit(root);
}
#endif

esp_err_t initialize_camera()
{
    camera_config_t config = {};
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
    config.xclk_freq_hz = 20000000;
    config.pixel_format = PIXFORMAT_JPEG;
    config.frame_size = FRAMESIZE_QVGA;
    config.jpeg_quality = 12;
    config.fb_count = 1;
    config.fb_location = CAMERA_FB_IN_PSRAM;
    config.grab_mode = CAMERA_GRAB_WHEN_EMPTY;
    esp_err_t result = esp_camera_init(&config);
    if (result != ESP_OK) {
        return result;
    }
    sensor_t *sensor = esp_camera_sensor_get();
    if (!sensor || sensor->id.PID != OV2640_PID) {
        ESP_LOGE(TAG, "Expected OV2640. Check the camera sensor and GPIO profile.");
        return ESP_ERR_NOT_SUPPORTED;
    }
    int flip = 0;
    int mirror = 0;
#if CONFIG_ROBOT_CAMERA_VFLIP
    flip = 1;
#endif
#if CONFIG_ROBOT_CAMERA_HMIRROR
    mirror = 1;
#endif
    if (sensor->set_vflip(sensor, flip) != 0 || sensor->set_hmirror(sensor, mirror) != 0) {
        return ESP_FAIL;
    }
    return ESP_OK;
}

void emit_detections(const std::list<dl::detect::result_t> &results, int width, int height,
                     uint64_t sequence, uint64_t captured_at_ms, double inference_ms, double processing_ms)
{
    cJSON *root = event("vision_frame", "no_target");
    if (!root) {
        emit(nullptr);
        return;
    }
    cJSON *targets = cJSON_AddArrayToObject(root, "detections");
    if (!targets) {
        cJSON_Delete(root);
        emit_error("json_allocation_failed", sequence);
        return;
    }
    for (const auto &result : results) {
        robotsys::TargetDetection target;
        if (!robotsys::make_target_detection(result.category, result.score, result.box.data(), result.box.size(),
                                             width, height, THRESHOLD, captured_at_ms, target)) {
            continue;
        }
        cJSON *item = cJSON_CreateObject();
        if (!item) {
            cJSON_Delete(root);
            emit_error("json_allocation_failed", sequence);
            return;
        }
        cJSON_AddNumberToObject(item, "class_id", target.class_id);
        cJSON_AddStringToObject(item, "label", target.label);
        cJSON_AddNumberToObject(item, "confidence", target.confidence);
        cJSON_AddNumberToObject(item, "timestamp_ms", target.timestamp_ms);
        cJSON_AddStringToObject(item, "direction", target.direction);
        cJSON *box = cJSON_AddObjectToObject(item, "bbox");
        cJSON_AddNumberToObject(box, "x", target.x);
        cJSON_AddNumberToObject(box, "y", target.y);
        cJSON_AddNumberToObject(box, "width", target.width);
        cJSON_AddNumberToObject(box, "height", target.height);
        cJSON *center = cJSON_AddObjectToObject(item, "center");
        cJSON_AddNumberToObject(center, "x", target.center_x);
        cJSON_AddNumberToObject(center, "y", target.center_y);
        if (!cJSON_AddItemToArray(targets, item)) {
            cJSON_Delete(item);
            cJSON_Delete(root);
            emit_error("json_allocation_failed", sequence);
            return;
        }
    }
    const int count = cJSON_GetArraySize(targets);
    cJSON_ReplaceItemInObject(root, "status", cJSON_CreateString(count ? "detecting" : "no_target"));
    cJSON_AddTrueToObject(root, "valid_frame");
    cJSON_AddNumberToObject(root, "sequence", sequence);
    cJSON_AddNumberToObject(root, "captured_at_ms", captured_at_ms);
    cJSON_AddNumberToObject(root, "frame_age_ms", uptime_ms() - captured_at_ms);
    cJSON_AddNumberToObject(root, "frame_width", width);
    cJSON_AddNumberToObject(root, "frame_height", height);
    cJSON_AddNumberToObject(root, "target_count", count);
    cJSON_AddNumberToObject(root, "raw_detection_count", results.size());
    cJSON_AddNumberToObject(root, "confidence_threshold", THRESHOLD);
    cJSON_AddNumberToObject(root, "inference_ms", inference_ms);
    cJSON_AddNumberToObject(root, "processing_ms", processing_ms);
    cJSON_AddNumberToObject(root, "free_psram_bytes", heap_caps_get_free_size(MALLOC_CAP_SPIRAM));
    // Also add device_id for Laravel API compatibility
    cJSON_AddStringToObject(root, "device_id", CONFIG_ROBOT_VISION_DEVICE_ID);
    // Post to Laravel if WiFi connected
    char *json_str = cJSON_PrintUnformatted(root);
    if (json_str) {
        http_post_detection(json_str);
        cJSON_free(json_str);
    }
    emit(root);
}

void run_vision()
{
    uint32_t flash_size = 0;
    ESP_ERROR_CHECK(esp_flash_get_size(nullptr, &flash_size));
    const size_t psram_size = esp_psram_is_initialized() ? esp_psram_get_size() : 0;
    cJSON *hardware = event("vision_hardware", "booting");
    if (hardware) {
        cJSON_AddNumberToObject(hardware, "flash_bytes", flash_size);
        cJSON_AddNumberToObject(hardware, "psram_bytes", psram_size);
        cJSON_AddStringToObject(hardware, "idf_version", esp_get_idf_version());
    }
    emit(hardware);
    if (flash_size < 16 * 1024 * 1024 || psram_size < 8 * 1024 * 1024) {
        emit_error("requires_verified_16mb_flash_and_8mb_psram_profile");
        return;
    }
    const esp_err_t camera_status = initialize_camera();
    if (camera_status != ESP_OK) {
        ESP_LOGE(TAG, "Camera initialization: %s", esp_err_to_name(camera_status));
        emit_error("camera_initialization_failed_check_saved_gpio_profile");
        return;
    }
    ESP_LOGI(TAG, "Loading onboard model; free PSRAM: %u", unsigned(heap_caps_get_free_size(MALLOC_CAP_SPIRAM)));
    COCODetect detector(COCODetect::YOLO11N_320_S8_V1, false);
    detector.set_score_thr(THRESHOLD);
    detector.set_nms_thr(0.7f);
    if (!detector.get_raw_model()) {
        emit_error("model_load_failed");
        return;
    }
    emit(event("vision_ready", "ready"));
    uint64_t sequence = 0;
    unsigned failures = 0;
#if CONFIG_ROBOT_VISION_SERIAL_SNAPSHOT
    bool snapshot_attempted = false;
    unsigned snapshot_warmup_frames = 0;
#endif
    while (failures < 5) {
        ESP_ERROR_CHECK(esp_task_wdt_reset());
        camera_fb_t *frame = esp_camera_fb_get();
        ++sequence;
        const uint64_t acquired = frame ? static_cast<uint64_t>(frame->timestamp.tv_sec) * 1000 +
                                             frame->timestamp.tv_usec / 1000 : uptime_ms();
        const int64_t started = esp_timer_get_time();
        if (!frame) {
            emit_error("camera_capture_failed", sequence);
            ++failures;
        } else {
            const int width = frame->width;
            const int height = frame->height;
            dl::image::img_t image = {};
            if (frame->format == PIXFORMAT_JPEG && width == 320 && height == 240) {
                const dl::image::jpeg_img_t jpeg = {frame->buf, frame->len};
                image = dl::image::sw_decode_jpeg(jpeg, dl::image::DL_IMAGE_PIX_TYPE_RGB888);
            }
            if (!image.data || image.width != width || image.height != height) {
                heap_caps_free(image.data);
                emit_error("camera_jpeg_decode_or_dimensions_failed", sequence);
                ++failures;
            } else {
                const int64_t inference_started = esp_timer_get_time();
                auto &results = detector.run(image);
                const int64_t completed = esp_timer_get_time();
                heap_caps_free(image.data);
                emit_detections(results, width, height, sequence, acquired,
                                (completed - inference_started) / 1000.0, (completed - started) / 1000.0);
#if CONFIG_ROBOT_VISION_SERIAL_SNAPSHOT
                if (!snapshot_attempted && ++snapshot_warmup_frames >= 10) {
                    emit_snapshot(*frame, sequence, acquired);
                    snapshot_attempted = true;
                }
#endif
                failures = 0;
            }
            // Holding the only camera buffer prevents a frame queuing during a long inference.
            esp_camera_fb_return(frame);
        }
        ESP_ERROR_CHECK(esp_task_wdt_reset());
        vTaskDelay(pdMS_TO_TICKS(CONFIG_ROBOT_VISION_INTERVAL_MS));
    }
    emit_error("capture_failed_five_times_inference_stopped", sequence);
}

void vision_task(void *)
{
    ESP_ERROR_CHECK(esp_task_wdt_add(nullptr));
    run_vision();
    esp_camera_deinit();
    ESP_ERROR_CHECK(esp_task_wdt_delete(nullptr));
    vTaskDelete(nullptr);
}
} // namespace

extern "C" void app_main()
{
    esp_err_t ret = nvs_flash_init();
    if (ret == ESP_ERR_NVS_NO_FREE_PAGES || ret == ESP_ERR_NVS_NEW_VERSION_FOUND) {
        ESP_ERROR_CHECK(nvs_flash_erase());
        ret = nvs_flash_init();
    }
    ESP_ERROR_CHECK(ret);
    
    std::snprintf(boot_id, sizeof(boot_id), "%08lx%08lx", (unsigned long)esp_random(), (unsigned long)esp_random());
    if (strlen(CONFIG_ROBOT_WIFI_SSID) > 0) {
        wifi_init_sta();
    }
    if (xTaskCreatePinnedToCore(vision_task, "onboard_vision", 12 * 1024, nullptr, 5, nullptr, 1) != pdPASS) {
        emit_error("cannot_create_vision_task");
    }
}

