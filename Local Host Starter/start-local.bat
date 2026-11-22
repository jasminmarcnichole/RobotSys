@echo off
cd /d "%~dp0.."

echo Starting RobotSys YOLO26n inference server on http://127.0.0.1:8765 ...
start "RobotSys YOLO26n Server" cmd /k ".\storage\app\private\waste-training\yolo-windows-env\Scripts\python.exe resources\ml\trash_detection.py serve --weights storage\app\private\waste-training\yolo-experiment-20261110\run-calibrated\weights\best.pt --host 127.0.0.1 --port 8765 --imgsz 416"

echo.
echo Starting RobotSys Laravel server on http://0.0.0.0:8000 ...
echo   - Laptop browser: http://127.0.0.1:8000
echo   - Phone browser / Android APK: http://192.168.4.10:8000
echo   - Main ESP32: http://192.168.4.1
echo   - ESP32-CAM:  http://192.168.4.2
echo.
php artisan serve --host=0.0.0.0 --port=8000
