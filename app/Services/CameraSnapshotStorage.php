<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CameraSnapshotStorage
{
    /** @param array{x: int, y: int, width: int, height: int}|null $boundingBox */
    public function store(string $snapshot, ?array $boundingBox = null): string
    {
        if (! preg_match('/^data:image\/(?<type>png|jpeg|jpg|webp);base64,(?<data>[A-Za-z0-9+\/=]+)$/', $snapshot, $matches)) {
            throw ValidationException::withMessages([
                'camera_snapshot' => 'The camera snapshot must be a valid base64 image.',
            ]);
        }

        $image = base64_decode($matches['data'], true);

        if ($image === false) {
            throw ValidationException::withMessages([
                'camera_snapshot' => 'The camera snapshot could not be decoded.',
            ]);
        }

        $dimensions = @getimagesizefromstring($image);
        $declaredMime = $matches['type'] === 'jpg' ? 'image/jpeg' : 'image/'.$matches['type'];

        if ($dimensions === false || ($dimensions['mime'] ?? null) !== $declaredMime || $dimensions[0] * $dimensions[1] > 16777216) {
            throw ValidationException::withMessages([
                'camera_snapshot' => 'The snapshot must contain a valid JPEG, PNG, or WebP image of at most 16 megapixels.',
            ]);
        }

        $decodedImage = @imagecreatefromstring($image);

        if ($decodedImage === false) {
            throw ValidationException::withMessages([
                'camera_snapshot' => 'The snapshot image is damaged or incomplete.',
            ]);
        }

        imagedestroy($decodedImage);

        if ($boundingBox !== null && ($dimensions[0] < $boundingBox['x'] + $boundingBox['width'] || $dimensions[1] < $boundingBox['y'] + $boundingBox['height'])) {
            throw ValidationException::withMessages([
                'detection_bbox' => 'The detected object must be within the evidence image.',
            ]);
        }

        $extension = $declaredMime === 'image/jpeg' ? 'jpg' : $matches['type'];
        $path = 'robot-detections/'.Str::uuid().'.'.$extension;

        if (! Storage::disk('public')->put($path, $image)) {
            throw ValidationException::withMessages([
                'camera_snapshot' => 'The camera snapshot could not be stored.',
            ]);
        }

        return $path;
    }
}
