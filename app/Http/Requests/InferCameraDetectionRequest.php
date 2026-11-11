<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InferCameraDetectionRequest extends FormRequest
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
            'camera_snapshot' => ['required', 'string', 'max:3000000', 'regex:/^data:image\/(png|jpeg|jpg|webp);base64,[A-Za-z0-9+\/=]+$/'],
            'threshold' => ['sometimes', 'numeric', 'min:0.2', 'max:0.95'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'camera_snapshot.required' => 'A camera frame is required for YOLO26n inference.',
            'camera_snapshot.regex' => 'The camera frame must be a valid JPEG, PNG, or WebP data URL.',
            'camera_snapshot.max' => 'The camera frame is too large.',
            'threshold.numeric' => 'The score threshold must be a number.',
            'threshold.min' => 'The score threshold must be at least 0.20.',
            'threshold.max' => 'The score threshold may not exceed 0.95.',
        ];
    }
}
