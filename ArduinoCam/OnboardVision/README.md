# RobotSys Onboard Vision: Phase 1

This is separate ESP-IDF firmware, not an Arduino sketch or a laptop inference
service. Only the ESP32-S3 runs the detector. The existing CameraWebServer sketch,
Laravel dashboard, TensorFlow environment, and public models are not replaced.

## Hardware Inspection

Read-only `esptool flash-id` on COM8 identified:

- ESP32-S3 revision v0.2, dual-core, up to 240 MHz.
- 16 MB physical flash, quad flash interface, 3.3 V.
- 8 MB embedded PSRAM (AP_3v3).
- A CH340 USB-to-UART bridge. COM8 may change after reconnecting.

The exact board manufacturer/model remains unknown. The saved working sketch
selects `CAMERA_MODEL_ESP32S3_EYE`. This project directly includes its
`../CameraWebServer/board_config.h` and `camera_pins.h`, rather than inventing pins:

| Signal | GPIO |
| --- | --- |
| D0-D7 | 11, 9, 8, 10, 12, 18, 17, 16 |
| XCLK | 15 |
| SCCB SDA / SCL | 4 / 5 |
| VSYNC / HREF / PCLK | 6 / 7 / 13 |
| PWDN / RESET | -1 / -1 |

These are saved software settings, not an electrically verified board schematic.
The most recent Arduino cache for this repository also selects S3-EYE, QIO,
240 MHz, and `PSRAM=opi`; its two pin headers match the repository apart from
generated line directives. Its `FlashSize=4M` setting and the old partition CSV
do not identify physical flash capacity. Confirm the successfully uploaded sketch
was this build before flashing. This project's factory application partition is below 8 MB, with
the flash header configured for the measured 16 MB. OTA is not implemented.

## Model and Runtime

Pinned components: ESP-IDF 5.5.6, `espressif/coco_detect` 0.4.2,
`espressif/esp-dl` 3.3.14, `espressif/esp32-camera` 2.1.8.

The official int8 `coco_detect_yolo11n_320_s8_v1.espdl` for ESP32-S3 is downloaded
by the component manager and embedded in application flash. No fake model,
manually guessed tensor decoding, or generic `.pt`/`.tflite` substitution is used.
The dependency lock file records the actual resolved components.

The pipeline is OV2640 JPEG at 320x240 -> ESP-DL JPEG decoder -> RGB888 ->
official letterbox/normalization/int8 preprocessing at 320x320 -> onboard model
-> official YOLO postprocessing/NMS -> bottle/cup filter -> serial JSON.
The vendor postprocessor maps boxes back to the original camera coordinates.

Only zero-based COCO classes 39 (`bottle`) and 41 (`cup`) are emitted. These
categories do not establish material, recyclability, or whether an object is
trash. Cans, paper, and wrappers require an embedded-compatible custom model;
they are NOT relabeled from unrelated COCO categories. Existing desktop YOLO
weights and TensorFlow models are not directly compatible ESP-DL binaries.

One camera frame is acquired at a time. Frame and decoded buffers are released
after use. Model weights remain flash-backed rather than copied into PSRAM.
The decoded 320x240 RGB888 frame alone uses 230,400 bytes; model tensors,
camera/DMA buffers, RTOS stacks, and JSON share the remaining memory.
Boot checks require the measured 16 MB flash / 8 MB PSRAM profile and an OV2640.
Memory allocation or repeated capture failures do not create detections.

ESP-IDF is used because these pinned Espressif components and quantized models
target its build/configuration system. Arduino as an IDF component is possible,
but is not needed for this isolated firmware. ESP-WHO is not required for this
minimal camera + ESP-DL application.

## Install and Build

Use Espressif's ESP-IDF 5.5.6 installer/VS Code extension on Windows, or its Linux
installation procedure. Do not install these tools into the TensorFlow venv.
In an activated ESP-IDF terminal:

```text
cd E:\Laravel\RobotSys\ArduinoCam\OnboardVision
idf.py -B build-windows set-target esp32s3
idf.py -B build-windows menuconfig
idf.py -B build-windows build
idf.py -B build-windows size
```

Check flash 16 MB, QIO 80 MHz, octal PSRAM 80 MHz, CPU 240 MHz, and the 320 model
in flash rodata. `RobotSys onboard vision` configures confidence (default 50%),
pause between inferences (200 ms), vertical flip (on), and horizontal mirror
(off). The pause is NOT the inference duration or a promised frame rate.

For a camera-framing diagnostic, enable `Export one processed JPEG over
USB serial for diagnosis` in `RobotSys onboard vision`. It is off by default.
The firmware then exports one real JPEG in a `vision_snapshot` JSON event after
ten successful inferences, allowing exposure and white balance to settle.
Its `boot_id`, `sequence`, and `captured_at_ms`
identify the matching detection frame. Decode `jpeg_base64` to inspect the
camera image; it is not another inference service. This one-time transfer slows
the following capture and puts an image in the serial log, so keep logs private
and disable the option for normal monitoring.

The isolated toolchain on this machine is installed in the RobotSys-Ubuntu WSL
distribution under `/home/robotsys/esp-onboard`. From its Bash terminal:

```bash
export IDF_TOOLS_PATH=/home/robotsys/esp-onboard/tools
source /home/robotsys/esp-onboard/esp-idf-v5.5.6/export.sh
cd /mnt/e/Laravel/RobotSys/ArduinoCam/OnboardVision
idf.py build
```

WSL can build without taking ownership of COM8. Do not reuse its `build` CMake
cache in a native Windows terminal; Windows uses `build-windows` instead.
Build files and downloaded components are ignored by Git. Keep
`dependencies.lock` for reproducibility.

## Flashing Is a Separate, Explicit Step

No flash operation is part of setup or tests. Keep motors disconnected. Close
Arduino Serial Monitor and any serial terminal first. Flashing replaces
CameraWebServer; its live web feed will stop until it is restored. Phase 1 does
not start Wi-Fi, HTTP streaming, UART-to-controller traffic, or any motor output.

Before the first flash, use esptool to back up the entire 16 MB flash into private
storage, and retain the original working Arduino sketch/settings. Do not publish
the flash backup: it can contain Wi-Fi credentials. Do not erase the whole chip.

When flashing is authorized, use the build's generated `flash_args` to avoid
guessing offsets. From Windows PowerShell in this project's `build` directory:

```powershell
& 'C:\Users\Admin\AppData\Local\Arduino15\packages\esp32\tools\esptool_py\5.3.1\esptool.exe' --chip esp32s3 --port COM8 --baud 230400 write-flash '@flash_args'
```

Alternatively, after building natively on Windows,
use `idf.py -B build-windows -p COM8 flash monitor`. Use 115200 baud for serial monitoring. If
automatic bootloader entry fails, use the board's documented BOOT/RESET sequence.
No programming voltage or GPIO wiring should be guessed for an unknown board.
Arduino IDE's Serial Monitor can also read the installed ESP-IDF firmware at
115200 baud. Close it before using esptool. Press RESET to see the boot events
and an enabled one-time diagnostic snapshot again; this does not reflash the
board or run a laptop detector.

### Verified Recovery Image

The 2026-10-29 pre-installation backup is private:

```text
storage/app/private/onboard-vision/pre-flash-20261029/original-full-16mb.bin
Bytes: 16777216
SHA256: 0d0e6f414162be89ef1c076cbbf3442a64441c9a36c761b60fc1816a9655ad84
```

The first 4 MB was read in 1 MB sections. The remaining 12 MB was independently
verified as erased before combining the image. `esptool verify-flash` then
confirmed the entire 16 MB backup matches the device. A previous whole-flash
read at 460800 baud failed with corrupted serial data; it is not a valid backup.
Use 230400 baud, or 115200 if USB transfer errors recur.

Only to intentionally restore the previous CameraWebServer firmware, close the
serial monitor and verify this backup's size and SHA256 first. From PowerShell:

```powershell
& 'C:\Users\Admin\AppData\Local\Arduino15\packages\esp32\tools\esptool_py\5.3.1\esptool.exe' --chip esp32s3 --port COM8 --baud 230400 write-flash --flash-mode keep --flash-freq keep --flash-size keep 0x0 'E:\Laravel\RobotSys\storage\app\private\onboard-vision\pre-flash-20261029\original-full-16mb.bin'
```

This replaces the onboard-vision installation with the original flash contents,
including its original partition table and saved settings. It does not require
`erase-flash`. Keep the backup private: it may contain network credentials.

## Hardware Acceptance Test

1. Verify the boot hardware event, camera initialization, model loading, and
   `vision_ready` with no allocation failure or watchdog reset.
2. Show a real bottle, then a real cup, in good light. Move each left, center,
   and right. Check changing pixel boxes, confidence, centers, and directions.
3. Remove all targets. Confirm a completed `vision_frame` has `no_target`,
   `target_count: 0`, and an empty detections array, not a repeated old target.
4. Close the Laravel/browser detector and disable the Wi-Fi access point while
   retaining USB/external power. Serial inference must continue unchanged.
5. Run at least ten minutes; record actual latency, free PSRAM, failures, and
   manually reviewed hits/misses. Vary distance, lighting, and clutter.
6. Verify camera failures emit `vision_error` (`valid_frame: false`), not a
   successful empty detection. Five consecutive failures stop the inference task.

This test requires flashing permission and physical objects. A successful build
or host unit test is not evidence that live hardware detection has passed.

## Serial Contract and Timing

Each JSON event is one newline-terminated line; boot/ESP-IDF log lines also share
the console and must be ignored by a JSON consumer. Completed frames include
`boot_id`, `sequence`, `inference_source: esp32s3_onboard`, model identity,
`frame_width`, `frame_height`, `captured_at_ms`, `target_count`, `detections`,
`frame_age_ms`, `inference_ms`, `processing_ms`, and `free_psram_bytes`.

Every detection includes `class_id`, `label`, `confidence` in [0,1], pixel
`bbox: {x,y,width,height}`, `center: {x,y}`, `direction`, and `timestamp_ms`.
Direction uses image thirds: x < width/3 is LEFT, x >= 2*width/3 is RIGHT.
It is image-relative, not a measured distance or a world/navigation heading.
Boxes follow ESP-DL's clipped xyxy convention converted to xywh; their far edge
is at most width-1/height-1. Coordinates reflect the configured sensor flips.

Timestamps are monotonic uptime milliseconds, NOT UTC. Detection timestamps
use the camera driver's first-DMA-buffer timestamp, not the time a queued frame
is delivered to the task. The event timestamp identifies completion/reporting.
`frame_age_ms` includes capture/queue time and processing up to report creation.
The single camera buffer is held through inference to avoid queuing an older
frame during model execution.
They reset on reboot, so consumers must also use `boot_id` and `sequence`.
Counts are per frame, not unique collected objects. No target tracking is claimed.
`inference_ms` includes ESP-DL preprocessing and postprocessing; `processing_ms`
also includes JPEG decoding, but not camera acquisition, serial output, or the
inter-frame pause. Use successive frame timestamps to measure the overall rate.

Espressif's 0.4.2 model table reports about 962 ms model execution plus about
30 ms preprocessing/postprocessing on its configured S3 test hardware. This is
not a measured speed for this board. Capture, JPEG decoding, the 200 ms pause,
and serial output add time. Concurrent Wi-Fi streaming will need a later memory
and latency check. Do not use this development rate for moving-robot safety.
The published COCO mAP50-95 is 0.276 across 80 classes, not 27.6% bottle/cup
accuracy or measured trash accuracy. Confidence scores are not accuracy scores.

## Installation Measurements

The firmware was installed on COM8 on 2026-10-29 after a full 16 MB recovery
image was verified against the device. Camera initialization identifies the
OV2640, the 8 MB PSRAM boot test passes, and ESP-DL reports `vision_ready`.
The warm-up diagnostic build SHA256 is
`4d68eb68ebfd6ff27a0e49defa4e07aca1180c1873857a10765669bc3f3bdf18`.

In its 54-frame observation, average onboard inference was 1363.92 ms and
JPEG decoding plus inference was 1376.44 ms. Free PSRAM at report time stayed
at 7,247,444 bytes; this is not a peak-memory measurement. A prior run's usual
complete-result interval was about 1.61 seconds. A diagnostic JPEG adds a
one-time serial-transfer delay, so exclude that interval when comparing speed.

The initial observations returned no accepted bottle/cup detection at 50% confidence.
The settled diagnostic image matches inference frame 10 exactly, but shows a
dark scene with the bottle's base cropped. Field accuracy and the ten-minute
stability test are not yet passed. An earlier serial
observation recorded 237 frames without a device-reported error before the USB
port closed; do not count that interrupted run as a ten-minute pass.

After the operator adjusted the target and lighting, a further 54-frame run
produced two actual onboard `bottle` detections, at 62.2% and 88.1% confidence,
both `CENTER`, with changing bounding boxes. No device-reported errors occurred.
This verifies the first positive onboard-inference milestone, not reliable
detection or 88.1% accuracy. The scene remained dark and the bottle base cropped;
the run is not a continuously labeled evaluation sequence. Cup detection,
LEFT/RIGHT positions, controlled target removal, and long-run stability still
need physical acceptance tests. The saved diagnostic JPEG is frame 10; positive
detections were frames 38 and 40. Do not draw those boxes over the earlier JPEG.
Wi-Fi transport and dashboard consumption are not yet implemented.

The full PHPUnit run had 131 passing tests and one 480-second TensorFlow
checkpoint timeout. That test passed alone with all six assertions after the
firmware compiler stopped; the timeout was not increased. The focused firmware
contract test, Pint, cross-compilation, and image validation pass. Detailed
private results are in
`storage/app/private/onboard-vision/installation-20261029.json`.

## Dashboard Integration and Scope

The existing machine-vision UI is in `resources/views/robot/partials/camera.blade.php`
and `resources/js/robot-camera.js` / `robot-ui.js`; `vision-worker.js` currently runs MediaPipe on
the client. `CameraDetectionController` handles existing browser-reviewed logs.
Those are inspected but unchanged: the dashboard does NOT yet consume these
serial events. Phase 2 must add an explicit onboard source and network transport,
frame/box alignment, authentication, freshness, and offline handling without
silently falling back to client inference. Prefer displaying each processed
JPEG with the detections from that exact frame; drawing older boxes over an
unrelated live-stream frame can misrepresent a moving object. Start with one
capture/inference/report cycle at a time, then measure the memory and latency
cost before adding a separate preview stream.

The current scope is this camera and the existing Machine Vision dashboard
only. Communication with another ESP32, motors, sensors, navigation, obstacle
avoidance, and collection mechanisms are excluded.

## References

- [ESP-IDF 5.5.6 setup](https://docs.espressif.com/projects/esp-idf/en/v5.5.6/esp32s3/get-started/index.html)
- [Pinned COCO model, memory, and benchmark](https://components.espressif.com/components/espressif/coco_detect/versions/0.4.2/readme)
- [Pinned camera driver](https://components.espressif.com/components/espressif/esp32-camera/versions/2.1.8/readme)
- [ESP-Detection for future small custom models](https://github.com/espressif/esp-detection)

Review the component/model upstream license terms before deployment, especially
when custom training involves Ultralytics code or pretrained weights.
