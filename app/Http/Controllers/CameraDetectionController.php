<?php

namespace App\Http\Controllers;

use App\Http\Requests\InferCameraDetectionRequest;
use App\Http\Requests\StoreCameraDetectionRequest;
use App\Models\RobotLog;
use App\Services\CameraSnapshotStorage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CameraDetectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $robotId = $request->query('robot_id', 'ROBOT-001');
        $robotId = is_string($robotId) ? $robotId : 'ROBOT-001';

        return response()->json(RobotLog::query()
            ->where('robot_id', $robotId)
            ->whereIn('detection_source', ['laptop_camera', 'robot_camera'])
            ->latest()->orderByDesc('id')->limit(8)->get());
    }

    public function infer(InferCameraDetectionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $parts = explode(',', $validated['camera_snapshot'], 2);
        $image = base64_decode($parts[1] ?? '', true);
        $dimensions = is_string($image) ? @getimagesizefromstring($image) : false;

        if (! is_string($image) || $dimensions === false
            || ! in_array($dimensions['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
            || $dimensions[0] * $dimensions[1] > 16777216) {
            return response()->json(['ok' => false, 'message' => 'Camera frame is not a valid image.'], 422);
        }

        if (function_exists('imagecreatefromstring')) {
            $decodedImage = @imagecreatefromstring($image);
            if ($decodedImage === false) {
                return response()->json(['ok' => false, 'message' => 'Camera frame could not be decoded.'], 422);
            }
            imagedestroy($decodedImage);
        }

        $baseUrl = config('services.robot.yolo_infer_url');
        if (! is_string($baseUrl) || ! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return response()->json(['ok' => false, 'message' => 'YOLO26n inference service is not configured.'], 503);
        }

        $inferUrl = rtrim($baseUrl, '/').'/infer';

        try {
            $response = Http::connectTimeout(2)
                ->timeout(5)
                ->withoutRedirecting()
                ->withBody($image, $dimensions['mime'])
                ->post($inferUrl);
        } catch (ConnectionException) {
            if (! $this->ensureYoloDaemonRunning($baseUrl)) {
                return response()->json(['ok' => false, 'message' => 'YOLO26n inference service is unavailable.'], 503);
            }

            try {
                $response = Http::connectTimeout(2)
                    ->timeout(6)
                    ->withoutRedirecting()
                    ->withBody($image, $dimensions['mime'])
                    ->post($inferUrl);
            } catch (ConnectionException) {
                return response()->json(['ok' => false, 'message' => 'YOLO26n inference service is unavailable.'], 503);
            }
        }

        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload) || ($payload['ok'] ?? false) !== true || ! is_array($payload['detections'] ?? null)) {
            return response()->json(['ok' => false, 'message' => 'YOLO26n inference failed.'], 502);
        }

        return response()->json([
            'ok' => true,
            'backend' => $payload['backend'] ?? 'YOLO26n Calibrated',
            'inference_ms' => (float) ($payload['inference_ms'] ?? 0),
            'detections' => $payload['detections'],
        ]);
    }

    public function snapshot(): Response|JsonResponse
    {
        $url = config('services.robot.camera_snapshot_url');

        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return response()->json(['message' => 'ESP32-CAM is not configured.'], 503);
        }

        try {
            $response = Http::connectTimeout(3)->timeout(5)->withoutRedirecting()->get($url);
        } catch (ConnectionException) {
            return response()->json(['message' => 'ESP32-CAM is unreachable. Check its power and network connection.'], 502);
        }

        $image = $response->body();
        $dimensions = @getimagesizefromstring($image);

        if (! $response->successful() || strlen($image) > 2250000 || $dimensions === false
            || ! in_array($dimensions['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
            || $dimensions[0] * $dimensions[1] > 16777216) {
            return response()->json(['message' => 'ESP32-CAM did not return a valid snapshot.'], 502);
        }

        $decodedImage = @imagecreatefromstring($image);

        if ($decodedImage === false) {
            return response()->json(['message' => 'ESP32-CAM returned a damaged or incomplete image.'], 502);
        }

        imagedestroy($decodedImage);

        return response($image, 200, [
            'Content-Type' => $dimensions['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(StoreCameraDetectionRequest $request, CameraSnapshotStorage $snapshots): JsonResponse
    {
        $validated = $request->validated();
        $latestTelemetry = RobotLog::query()->telemetry()
            ->where('robot_id', $validated['robot_id'])
            ->latest()->orderByDesc('id')->first();
        $path = $snapshots->store($validated['camera_snapshot'], $validated['detection_bbox']);
        $captureSource = $validated['capture_source'] ?? 'browser_camera';
        $modelId = $validated['detection_model'] ?? 'efficientdet_lite0';
        $modelName = config('services.robot.vision_models.'.$modelId.'.name');
        unset($validated['camera_snapshot'], $validated['capture_source'], $validated['detection_model']);

        try {
            $log = RobotLog::query()->create(array_merge(
                $latestTelemetry?->only([
                    'battery_level', 'detected_trash_count', 'successful_pickup_count',
                    'failed_pickup_count', 'obstacle_count', 'operating_duration',
                    'plastic_waste_count', 'general_waste_count', 'plastic_bin_level', 'general_bin_level',
                ]) ?? [],
                $validated,
                [
                    'movement_status' => 'Camera Detection',
                    'detection_source' => 'laptop_camera',
                    'classification_model' => 'TensorFlow Lite / '.$modelName,
                    'camera_snapshot_path' => $path,
                    'remarks' => 'Operator-reviewed '.$captureSource.' observation. Material category confirmed manually; collection not recorded.',
                ],
            ));
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);

            throw $exception;
        }

        return response()->json(['success' => true, 'data' => $log], 201);
    }

    private function ensureYoloDaemonRunning(string $baseUrl): bool
    {
        if (app()->runningUnitTests()) {
            return false;
        }

        $weights = config('services.robot.yolo_weights');
        $python = storage_path('app/private/waste-training/yolo-windows-env/Scripts/python.exe');
        $script = resource_path('ml/trash_detection.py');
        $port = (int) (parse_url($baseUrl, PHP_URL_PORT) ?: 8765);

        if (! is_string($weights) || ! is_file($weights) || ! is_file($python) || ! is_file($script)) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $psQuote = static fn (string $value): string => "'".str_replace("'", "''", $value)."'";
            $psScript = sprintf(
                'Start-Process -FilePath %s -ArgumentList @(%s, %s, %s, %s, %s, %s, %s, %s, %s, %s) -WindowStyle Hidden',
                $psQuote($python),
                $psQuote($script),
                $psQuote('serve'),
                $psQuote('--weights'),
                $psQuote($weights),
                $psQuote('--host'),
                $psQuote('127.0.0.1'),
                $psQuote('--port'),
                $psQuote((string) $port),
                $psQuote('--imgsz'),
                $psQuote('416'),
            );
            $encoded = base64_encode((string) iconv('UTF-8', 'UTF-16LE', $psScript));
            @pclose(@popen('powershell -NoProfile -NonInteractive -EncodedCommand '.$encoded, 'r'));
        } else {
            $command = sprintf(
                '%s %s serve --weights %s --host 127.0.0.1 --port %d --imgsz 416 >/dev/null 2>&1 &',
                escapeshellarg($python),
                escapeshellarg($script),
                escapeshellarg($weights),
                $port
            );
            @pclose(@popen($command, 'r'));
        }

        $healthUrl = rtrim($baseUrl, '/').'/health';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            usleep(250000);
            try {
                if (Http::connectTimeout(1)->timeout(1)->get($healthUrl)->successful()) {
                    return true;
                }
            } catch (ConnectionException) {
                continue;
            }
        }

        return false;
    }
}
