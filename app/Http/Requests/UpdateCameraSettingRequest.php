<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCameraSettingRequest extends FormRequest
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
            'detection_model' => ['required', 'string', Rule::in(array_keys(config('services.robot.vision_models', [])))],
            'confidence_threshold' => ['required', 'integer', 'min:20', 'max:90'],
            'auto_grab_enabled' => ['required', 'boolean'],
            'framesize' => ['required', 'integer', Rule::in([5, 6, 8, 9, 10])],
            'quality' => ['required', 'integer', 'min:6', 'max:30'],
            'brightness' => ['required', 'integer', 'min:-2', 'max:2'],
            'contrast' => ['required', 'integer', 'min:-2', 'max:2'],
            'saturation' => ['required', 'integer', 'min:-2', 'max:2'],
            'vflip' => ['required', 'boolean'],
            'hmirror' => ['required', 'boolean'],
            'led_intensity' => ['required', 'integer', 'min:0', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'detection_model.in' => 'Choose one of the supported detection models.',
            'confidence_threshold.min' => 'Minimum confidence must be at least 20%.',
            'confidence_threshold.max' => 'Minimum confidence cannot exceed 90%.',
            'framesize.in' => 'Choose a supported ESP32-CAM resolution (QVGA, HVGA, VGA, SVGA, or XGA).',
            'quality.min' => 'JPEG quality value must be between 6 and 30.',
            'quality.max' => 'JPEG quality value must be between 6 and 30.',
            'brightness.min' => 'Brightness must be between -2 and +2.',
            'brightness.max' => 'Brightness must be between -2 and +2.',
            'contrast.min' => 'Contrast must be between -2 and +2.',
            'contrast.max' => 'Contrast must be between -2 and +2.',
            'saturation.min' => 'Saturation must be between -2 and +2.',
            'saturation.max' => 'Saturation must be between -2 and +2.',
            'led_intensity.min' => 'Flash LED intensity must be between 0 and 255.',
            'led_intensity.max' => 'Flash LED intensity must be between 0 and 255.',
        ];
    }
}
