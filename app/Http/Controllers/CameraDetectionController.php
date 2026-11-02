<?php

namespace App\Http\Controllers;

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
        return response()->json(RobotLog::query()
            ->where('robot_id', $request->query('robot_id', 'ROBOT-001'))
            ->whereIn('detection_source', ['laptop_camera', 'robot_camera'])
            ->latest()->orderByDesc('id')->limit(8)->get());
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
}
