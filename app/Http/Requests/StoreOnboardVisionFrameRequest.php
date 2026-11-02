<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class StoreOnboardVisionFrameRequest extends FormRequest
{
    private ?string $decodedJpeg = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $device = $this->input('device_id');
        $token = $this->user()?->currentAccessToken();

        return is_string($device) && $token instanceof PersonalAccessToken
            && in_array('vision:device:'.$device, $token->abilities ?? [], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,50}$/'],
            'schema_version' => ['required', 'integer', 'in:1'],
            'type' => ['required', Rule::in(['vision_frame', 'vision_error'])],
            'status' => ['required', Rule::in(['detecting', 'no_target', 'error'])],
            'boot_id' => ['required', 'string', 'regex:/^[a-f0-9]{16}$/'],
            'inference_source' => ['required', 'in:esp32s3_onboard'],
            'model' => ['required', Rule::in([config('services.robot.onboard.model')])],
            'timestamp_clock' => ['required', 'in:uptime_ms'],
            'timestamp_ms' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'sequence' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'valid_frame' => ['required', 'boolean'],
            'reason' => ['required_if:type,vision_error', 'prohibited_if:type,vision_frame', 'string', 'regex:/^[a-z0-9_]{1,100}$/'],
            'captured_at_ms' => ['required_if:type,vision_frame', 'prohibited_if:type,vision_error', 'integer', 'min:0', 'lte:timestamp_ms'],
            'frame_age_ms' => ['required_if:type,vision_frame', 'prohibited_if:type,vision_error', 'integer', 'between:0,10000'],
            'frame_width' => ['required_if:type,vision_frame', 'prohibited_if:type,vision_error', 'integer', 'in:320'],
            'frame_height' => ['required_if:type,vision_frame', 'prohibited_if:type,vision_error', 'integer', 'in:240'],
            'target_count' => ['required_if:type,vision_frame', 'prohibited_if:type,vision_error', 'integer', 'between:0,10'],
            'raw_detection_count' => ['required_if:type,vision_frame', 'prohibited_if:type,vision_error', 'integer', 'between:0,10', 'gte:target_count'],
            'confidence_threshold' => ['required_if:type,vision_frame', 'prohibited_if:type,vision_error', 'numeric', 'between:0.25,0.95'],
            'inference_ms' => ['required_if:type,vision_frame', 'prohibited_if:type,vision_error', 'numeric', 'between:0,10000'],
            'processing_ms' => ['required_if:type,vision_frame', 'prohibited_if:type,vision_error', 'numeric', 'between:0,10000', 'gte:inference_ms'],
            'free_psram_bytes' => ['required_if:type,vision_frame', 'prohibited_if:type,vision_error', 'integer', 'between:0,8388608'],
            'detections' => ['present_if:type,vision_frame', 'prohibited_if:type,vision_error', 'array', 'max:10'],
            'detections.*' => ['array:class_id,label,confidence,timestamp_ms,direction,bbox,center'],
            'detections.*.class_id' => ['required', 'integer', Rule::in([39, 41])],
            'detections.*.label' => ['required', Rule::in(['bottle', 'cup'])],
            'detections.*.confidence' => ['required', 'numeric', 'between:0,1'],
            'detections.*.timestamp_ms' => ['required', 'integer', 'min:0'],
            'detections.*.direction' => ['required', Rule::in(['LEFT', 'CENTER', 'RIGHT'])],
            'detections.*.bbox' => ['required', 'array:x,y,width,height'],
            'detections.*.bbox.x' => ['required', 'integer', 'between:0,319'],
            'detections.*.bbox.y' => ['required', 'integer', 'between:0,239'],
            'detections.*.bbox.width' => ['required', 'integer', 'between:1,319'],
            'detections.*.bbox.height' => ['required', 'integer', 'between:1,239'],
            'detections.*.center' => ['required', 'array:x,y'],
            'detections.*.center.x' => ['required', 'numeric', 'between:0,319'],
            'detections.*.center.y' => ['required', 'numeric', 'between:0,239'],
            'frame_jpeg_base64' => ['required_if:type,vision_frame', 'prohibited_if:type,vision_error', 'string', 'max:90000'],
        ];
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->input('type') === 'vision_error') {
                if ($this->boolean('valid_frame') || $this->input('status') !== 'error') {
                    $validator->errors()->add('status', 'An error must be marked as an invalid frame.');
                }

                return;
            }
            $detections = $this->input('detections');
            $count = count($detections);
            if (! $this->boolean('valid_frame') || (int) $this->input('sequence') < 1
                || $count !== (int) $this->input('target_count')
                || $this->input('status') !== ($count > 0 ? 'detecting' : 'no_target')) {
                $validator->errors()->add('status', 'Frame status and object count must match the detections.');
            }
            foreach ($detections as $index => $detection) {
                $box = $detection['bbox'];
                $x = $box['x'] + $box['width'] / 2;
                $y = $box['y'] + $box['height'] / 2;
                $direction = $x < 320 / 3 ? 'LEFT' : ($x >= 640 / 3 ? 'RIGHT' : 'CENTER');
                if ($detection['label'] !== ((int) $detection['class_id'] === 39 ? 'bottle' : 'cup')
                    || (float) $detection['confidence'] < (float) $this->input('confidence_threshold')
                    || (int) $detection['timestamp_ms'] !== (int) $this->input('captured_at_ms')
                    || $box['x'] + $box['width'] > 319 || $box['y'] + $box['height'] > 239
                    || abs($detection['center']['x'] - $x) > 0.0001
                    || abs($detection['center']['y'] - $y) > 0.0001 || $detection['direction'] !== $direction) {
                    $validator->errors()->add("detections.$index", 'Object geometry, class, confidence, and capture timestamp must agree.');
                }
            }
            $jpeg = base64_decode($this->input('frame_jpeg_base64'), true);
            $dimensions = $jpeg === false ? false : @getimagesizefromstring($jpeg);
            if ($jpeg === false || strlen($jpeg) > 65536 || $dimensions === false
                || $dimensions[0] !== 320 || $dimensions[1] !== 240 || ($dimensions['mime'] ?? '') !== 'image/jpeg') {
                $validator->errors()->add('frame_jpeg_base64', 'Send the actual 320 by 240 camera JPEG, no larger than 64 KB.');

                return;
            }
            $image = @imagecreatefromstring($jpeg);
            if ($image === false) {
                $validator->errors()->add('frame_jpeg_base64', 'The camera JPEG is damaged or incomplete.');

                return;
            }
            imagedestroy($image);
            $this->decodedJpeg = $jpeg;
        }];
    }

    public function jpeg(): ?string
    {
        return $this->decodedJpeg;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'inference_source.in' => 'Only ESP32-S3 onboard results are accepted here.',
            'model.in' => 'This embedded model is not configured for the camera.',
            'frame_jpeg_base64.required_if' => 'A processed frame must include its matching camera JPEG.',
            'detections.present_if' => 'Send an empty detections array when no target is found.',
            'frame_age_ms.between' => 'The camera result is too old to publish as a fresh frame.',
            'device_id.regex' => 'Use a valid camera device identifier.',
        ];
    }
}
