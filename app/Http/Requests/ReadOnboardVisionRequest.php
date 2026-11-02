<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReadOnboardVisionRequest extends FormRequest
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
            'device_id' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_-]{1,50}$/'],
        ];
    }

    public function deviceId(): string
    {
        return $this->validated('device_id', config('services.robot.onboard.device_id'));
    }

    public function messages(): array
    {
        return ['device_id.regex' => 'Choose a valid camera device identifier.'];
    }
}
