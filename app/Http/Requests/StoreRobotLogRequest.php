<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRobotLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'robot_id' => 'nullable|string|max:50',
            'battery_level' => 'nullable|numeric|min:0|max:100',
            'movement_status' => 'required|string|max:100',
            'detected_trash_count' => 'nullable|integer|min:0',
            'successful_pickup_count' => 'nullable|integer|min:0',
            'failed_pickup_count' => 'nullable|integer|min:0',
            'obstacle_count' => 'nullable|integer|min:0',
            'operating_duration' => 'nullable|integer|min:0',
            'plastic_waste_count' => 'nullable|integer|min:0',
            'general_waste_count' => 'nullable|integer|min:0',
            'last_detected_trash_type' => 'nullable|string|max:100',
            'plastic_bin_level' => 'nullable|string|max:50',
            'general_bin_level' => 'nullable|string|max:50',
            'detection_source' => ['nullable', 'string', Rule::in(['robot_sensor', 'laptop_camera', 'robot_camera'])],
            'classification_model' => 'nullable|string|max:100',
            'detected_object_label' => 'nullable|string|max:100',
            'detection_confidence' => 'nullable|numeric|min:0|max:100',
            'detection_bbox' => 'nullable|array',
            'detection_bbox.x' => 'nullable|numeric|min:0',
            'detection_bbox.y' => 'nullable|numeric|min:0',
            'detection_bbox.width' => 'nullable|numeric|min:0',
            'detection_bbox.height' => 'nullable|numeric|min:0',
            'camera_snapshot' => ['nullable', 'string', 'max:3000000', 'regex:/^data:image\/(png|jpeg|jpg|webp);base64,[A-Za-z0-9+\/=]+$/'],
            'remarks' => 'nullable|string',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'movement_status.required' => 'The robot movement status is required.',
            'battery_level.max' => 'The battery level cannot be higher than 100%.',
            'battery_level.min' => 'The battery level cannot be negative.',
            'camera_snapshot.regex' => 'The camera snapshot must be a valid base64 image.',
            'camera_snapshot.max' => 'The camera snapshot is too large.',
        ];
    }
}
