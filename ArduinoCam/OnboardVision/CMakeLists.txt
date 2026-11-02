cmake_minimum_required(VERSION 3.16)
include($ENV{IDF_PATH}/tools/cmake/project.cmake)
project(robotsys_onboard_vision)
if(NOT IDF_TARGET STREQUAL "esp32s3")
    message(FATAL_ERROR "This firmware requires ESP32-S3. Run idf.py set-target esp32s3.")
endif()

