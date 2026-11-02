<?php

namespace App\Services;

use App\Models\OnboardVisionFrame;
use App\Models\OnboardVisionSession;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class OnboardVisionStorage
{
    /** @param array<string, mixed> $payload
     *  @return array{frame: OnboardVisionFrame, created: bool}
     */
    public function ingest(array $payload, ?string $jpeg): array
    {
        unset($payload['frame_jpeg_base64']);
        $payload['valid_frame'] = (bool) $payload['valid_frame'];
        $device = $payload['device_id'];
        $imageHash = $jpeg !== null ? hash('sha256', $jpeg) : null;
        $payloadHash = hash('sha256', json_encode(Arr::sortRecursive($payload), JSON_THROW_ON_ERROR).($imageHash ?? ''));

        try {
            return Cache::lock('onboard-vision:'.$device, 15)->block(2, function () use ($payload, $jpeg, $device, $imageHash, $payloadHash): array {
                $path = null;
                $prunedPaths = [];
                try {
                    $result = (new OnboardVisionSession)->getConnection()->transaction(function () use ($payload, $jpeg, $device, $imageHash, $payloadHash, &$path, &$prunedPaths): array {
                        $active = OnboardVisionSession::query()->where('device_id', $device)->latest('id')->lockForUpdate()->first();
                        $session = OnboardVisionSession::query()->where('device_id', $device)->where('boot_id', $payload['boot_id'])->first();
                        abort_if($session && $active && $session->id !== $active->id, 409, 'This camera boot session has been retired.');
                        $existing = $session?->frames()->where('sequence', $payload['sequence'])->first();
                        if ($existing) {
                            abort_unless(hash_equals($existing->payload_sha256, $payloadHash), 409, 'This sequence already has different frame data.');

                            return ['frame' => $existing, 'created' => false];
                        }
                        if ($session) {
                            abort_if($payload['sequence'] <= $session->last_sequence || $payload['timestamp_ms'] < $session->last_timestamp_ms, 409, 'Camera frames must arrive in monotonic order.');
                        } else {
                            $session = OnboardVisionSession::query()->create(['device_id' => $device, 'boot_id' => $payload['boot_id']]);
                        }
                        if ($jpeg !== null) {
                            $path = 'onboard-vision/frames/'.$device.'/'.Str::uuid().'.jpg';
                            if (! Storage::disk('local')->put($path, $jpeg)) {
                                throw new HttpException(503, 'Camera image storage is unavailable.');
                            }
                        }
                        $frame = $session->frames()->create([
                            'device_id' => $device,
                            'sequence' => $payload['sequence'],
                            'status' => $payload['status'],
                            'target_count' => $payload['target_count'] ?? 0,
                            'payload' => $payload,
                            'payload_sha256' => $payloadHash,
                            'snapshot_path' => $path,
                            'snapshot_sha256' => $imageHash,
                        ]);
                        $session->update(['last_sequence' => $payload['sequence'], 'last_timestamp_ms' => $payload['timestamp_ms']]);
                        $limit = max(8, min(300, (int) config('services.robot.onboard.retained_frames')));
                        $old = OnboardVisionFrame::query()->where('device_id', $device)->latest('id')->skip($limit)->take(300)->get(['id', 'snapshot_path']);
                        $prunedPaths = $old->pluck('snapshot_path')->filter()->all();
                        if ($old->isNotEmpty()) {
                            OnboardVisionFrame::query()->whereKey($old->modelKeys())->delete();
                        }

                        return ['frame' => $frame, 'created' => true];
                    });
                } catch (Throwable $exception) {
                    if ($path !== null) {
                        Storage::disk('local')->delete($path);
                    }
                    throw $exception;
                }
                if ($prunedPaths !== [] && ! Storage::disk('local')->delete($prunedPaths)) {
                    Log::warning('Some expired onboard camera images could not be removed.');
                }

                return $result;
            });
        } catch (LockTimeoutException) {
            throw new HttpException(503, 'Camera receiver is busy. Retry the next current frame.', null, ['Retry-After' => '2']);
        }
    }
}
