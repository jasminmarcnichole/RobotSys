<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRobotCommandStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(['received', 'completed', 'failed'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Provide the command status.',
            'status.in' => 'The selected command status is not supported.',
        ];
    }
}
