<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCameraDetectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'robot_id' => ['required', 'string', 'max:50'],
            'capture_source' => ['sometimes', Rule::in(['browser_camera', 'esp32_camera', 'uploaded_image'])],
            'detection_model' => ['sometimes', 'required', 'string', Rule::in(array_keys(config('services.robot.vision_models')))],
            'last_detected_trash_type' => ['required', Rule::in(['Plastic Waste', 'General Waste', 'Paper Waste', 'Metal Waste', 'Glass Waste', 'Organic Waste'])],
            'detected_object_label' => ['required', 'string', 'max:100'],
            'detection_confidence' => ['required', 'numeric', 'gt:0', 'max:100'],
            'detection_bbox' => ['required', 'array:x,y,width,height'],
            'detection_bbox.x' => ['required', 'integer', 'min:0'],
            'detection_bbox.y' => ['required', 'integer', 'min:0'],
            'detection_bbox.width' => ['required', 'integer', 'min:1'],
            'detection_bbox.height' => ['required', 'integer', 'min:1'],
            'camera_snapshot' => ['required', 'string', 'max:3000000', 'regex:/^data:image\/(png|jpeg|jpg|webp);base64,[A-Za-z0-9+\/=]+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'last_detected_trash_type.required' => 'Choose a waste category for the reviewed object.',
            'last_detected_trash_type.in' => 'Choose one of the supported waste categories.',
            'camera_snapshot.required' => 'Capture or upload an image before saving.',
            'camera_snapshot.regex' => 'The evidence must be a JPEG, PNG, or WebP image.',
            'camera_snapshot.max' => 'The evidence image is too large.',
            'detection_bbox.required' => 'A detected object is required before saving.',
            'detection_confidence.gt' => 'The detection must have a positive confidence score.',
            'detection_model.in' => 'Choose one of the supported detection models.',
            'detection_model.required' => 'The selected detection model is required.',
            'detection_model.string' => 'Choose a single detection model.',
        ];
    }
}
