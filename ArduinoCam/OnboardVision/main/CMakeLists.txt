idf_component_register(
    SRCS "app_main.cpp"
    INCLUDE_DIRS "." "../../CameraWebServer"
    REQUIRES espressif__coco_detect espressif__esp32-camera espressif__esp-dl
             esp_timer esp_psram spi_flash esp_system esp_driver_ledc json mbedtls
             esp_wifi esp_netif esp_event nvs_flash esp_http_client
)
target_compile_features(${COMPONENT_LIB} PUBLIC cxx_std_17)

