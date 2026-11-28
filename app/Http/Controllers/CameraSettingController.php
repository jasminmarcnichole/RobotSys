<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCameraSettingRequest;
use App\Models\CameraSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CameraSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $robotId = $request->query('robot_id', 'ROBOT-001');
        $robotId = is_string($robotId) && trim($robotId) !== '' ? trim($robotId) : 'ROBOT-001';

        return response()->json([
            'ok' => true,
            'data' => CameraSetting::forRobot($robotId)->toPayload(),
        ]);
    }

    public function update(UpdateCameraSettingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $setting = CameraSetting::query()->updateOrCreate(
            ['robot_id' => $validated['robot_id']],
            $validated,
        );

        $esp32Synced = $this->pushSettingsToEsp32($setting);

        return response()->json([
            'ok' => true,
            'message' => $esp32Synced
                ? 'Camera settings saved and applied to ESP32-CAM.'
                : 'Camera settings saved to database.',
            'esp32_synced' => $esp32Synced,
            'data' => $setting->toPayload(),
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'robot_id' => ['required', 'string', 'max:50'],
        ]);

        $setting = CameraSetting::query()->updateOrCreate(
            ['robot_id' => $validated['robot_id']],
            CameraSetting::defaults(),
        );

        $esp32Synced = $this->pushSettingsToEsp32($setting);

        return response()->json([
            'ok' => true,
            'message' => $esp32Synced
                ? 'Camera settings reset to defaults and applied to ESP32-CAM.'
                : 'Camera settings reset to defaults.',
            'esp32_synced' => $esp32Synced,
            'data' => $setting->toPayload(),
        ]);
    }

    private function pushSettingsToEsp32(CameraSetting $setting): bool
    {
        if (app()->runningUnitTests()) {
            return false;
        }

        $snapshotUrl = config('services.robot.camera_snapshot_url');
        if (! is_string($snapshotUrl) || ! filter_var($snapshotUrl, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($snapshotUrl);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $controlUrl = $parts['scheme'].'://'.$parts['host'].$port.'/control';

        $commands = [
            'framesize' => $setting->framesize,
            'quality' => $setting->quality,
            'brightness' => $setting->brightness,
            'contrast' => $setting->contrast,
            'saturation' => $setting->saturation,
            'vflip' => $setting->vflip ? 1 : 0,
            'hmirror' => $setting->hmirror ? 1 : 0,
            'led_intensity' => $setting->led_intensity,
        ];

        try {
            foreach ($commands as $var => $val) {
                $response = Http::connectTimeout(1)
                    ->timeout(2)
                    ->withoutRedirecting()
                    ->get($controlUrl, ['var' => $var, 'val' => $val]);

                if (! $response->successful()) {
                    return false;
                }
            }
        } catch (ConnectionException) {
            return false;
        }

        return true;
    }
}
