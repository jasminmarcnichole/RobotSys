<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReadOnboardVisionRequest;
use App\Http\Requests\StoreOnboardVisionFrameRequest;
use App\Http\Resources\OnboardVisionFrameResource;
use App\Models\OnboardVisionFrame;
use App\Services\OnboardVisionStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class OnboardVisionController extends Controller
{
    public function store(StoreOnboardVisionFrameRequest $request, OnboardVisionStorage $storage): JsonResponse
    {
        $result = $storage->ingest($request->validated(), $request->jpeg());

        return response()->json(['accepted' => true, 'id' => $result['frame']->id, 'duplicate' => ! $result['created']], $result['created'] ? 201 : 200);
    }

    public function latest(ReadOnboardVisionRequest $request): JsonResponse
    {
        $query = OnboardVisionFrame::query()->where('device_id', $request->deviceId());
        $latest = (clone $query)->latest('id')->first();
        $freshSeconds = max(5, min(30, (int) config('services.robot.onboard.stale_seconds')));
        $connected = $latest && $latest->created_at->greaterThanOrEqualTo(now()->subSeconds($freshSeconds));
        $history = (clone $query)->where('target_count', '>', 0)->latest('id')->limit(8)->get();

        return response()->json([
            'device_id' => $request->deviceId(),
            'connection_status' => $connected ? 'connected' : 'offline',
            'detection_status' => $connected ? $latest->status : 'offline',
            'server_time' => now()->toIso8601String(),
            'stale_after_ms' => $freshSeconds * 1000,
            'latest' => $latest ? (new OnboardVisionFrameResource($latest))->resolve($request) : null,
            'history' => OnboardVisionFrameResource::collection($history)->resolve($request),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function image(OnboardVisionFrame $onboardVisionFrame): Response
    {
        abort_unless($onboardVisionFrame->snapshot_path && Storage::disk('local')->exists($onboardVisionFrame->snapshot_path), 404);

        return response(Storage::disk('local')->get($onboardVisionFrame->snapshot_path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Vision-Frame-ID' => (string) $onboardVisionFrame->id,
            'X-Vision-Sequence' => (string) $onboardVisionFrame->sequence,
            'X-Vision-Boot-ID' => $onboardVisionFrame->payload['boot_id'],
        ]);
    }
}
