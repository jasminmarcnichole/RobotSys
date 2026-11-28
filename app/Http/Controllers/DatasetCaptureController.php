<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroyDatasetCaptureRequest;
use App\Http\Requests\StoreDatasetCaptureRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class DatasetCaptureController extends Controller
{
    public function index(): JsonResponse
    {
        $counts = $this->categoryCounts();

        return response()->json([
            'ok' => true,
            'counts' => $counts,
            'total' => array_sum($counts),
        ]);
    }

    public function store(StoreDatasetCaptureRequest $request): JsonResponse
    {
        $category = (string) $request->validated('category');
        $captureUrl = config('services.robot.camera_snapshot_url');

        if (! is_string($captureUrl) || ! filter_var($captureUrl, FILTER_VALIDATE_URL)) {
            return response()->json([
                'ok' => false,
                'message' => 'ESP32-CAM snapshot URL is not configured.',
            ], 503);
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(6)
                ->withoutRedirecting()
                ->get($captureUrl);
        } catch (ConnectionException) {
            return response()->json([
                'ok' => false,
                'message' => 'ESP32-CAM is unreachable. Check that your laptop is connected to TrashCollector_CAM or TrashCollector_Robot.',
            ], 502);
        }

        $image = $response->body();
        $dimensions = @getimagesizefromstring($image);

        if (! $response->successful()
            || strlen($image) === 0
            || strlen($image) > 4000000
            || $dimensions === false
            || ! in_array($dimensions['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return response()->json([
                'ok' => false,
                'message' => 'ESP32-CAM did not return a valid image frame.',
            ], 502);
        }

        if (function_exists('imagecreatefromstring')) {
            $decoded = @imagecreatefromstring($image);
            if ($decoded === false) {
                return response()->json([
                    'ok' => false,
                    'message' => 'ESP32-CAM returned a damaged or incomplete image.',
                ], 502);
            }
            imagedestroy($decoded);
        }

        $folder = $this->outputRoot().DIRECTORY_SEPARATOR.$category;
        File::ensureDirectoryExists($folder);

        $timestamp = now()->format('Ymd_His_v');
        $baseName = $category.'_'.$timestamp;
        $filename = $baseName.'.jpg';
        $jpgPath = $folder.DIRECTORY_SEPARATOR.$filename;

        File::put($jpgPath, $image);

        if ($category === 'background') {
            File::put($folder.DIRECTORY_SEPARATOR.$baseName.'.txt', '');
        }

        $counts = $this->categoryCounts();

        return response()->json([
            'ok' => true,
            'message' => sprintf(
                'Saved %s/%s (%.1f KB, %dx%d)',
                $category,
                $filename,
                strlen($image) / 1024,
                $dimensions[0],
                $dimensions[1],
            ),
            'capture' => [
                'category' => $category,
                'filename' => $filename,
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'size_kb' => round(strlen($image) / 1024, 1),
            ],
            'counts' => $counts,
            'total' => array_sum($counts),
        ], 201);
    }

    public function destroy(DestroyDatasetCaptureRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $folder = $this->outputRoot().DIRECTORY_SEPARATOR.$validated['category'];
        $jpgPath = $folder.DIRECTORY_SEPARATOR.$validated['filename'];

        if (! File::isFile($jpgPath)) {
            return response()->json([
                'ok' => false,
                'message' => 'That capture file was already removed.',
            ], 404);
        }

        File::delete($jpgPath);

        if ($validated['category'] === 'background') {
            $txtPath = preg_replace('/\.jpg$/', '.txt', $jpgPath);
            if (is_string($txtPath) && File::isFile($txtPath)) {
                File::delete($txtPath);
            }
        }

        $counts = $this->categoryCounts();

        return response()->json([
            'ok' => true,
            'message' => 'Deleted '.$validated['category'].'/'.$validated['filename'].'.',
            'counts' => $counts,
            'total' => array_sum($counts),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function categoryCounts(): array
    {
        $root = $this->outputRoot();
        $counts = [];

        foreach (StoreDatasetCaptureRequest::CATEGORIES as $category) {
            $folder = $root.DIRECTORY_SEPARATOR.$category;
            $counts[$category] = File::isDirectory($folder)
                ? count(File::glob($folder.DIRECTORY_SEPARATOR.'*.jpg'))
                : 0;
        }

        return $counts;
    }

    private function outputRoot(): string
    {
        $configured = config('services.robot.dataset_capture_dir');

        return is_string($configured) && $configured !== ''
            ? $configured
            : storage_path('app/private/waste-training/esp32-captures');
    }
}
