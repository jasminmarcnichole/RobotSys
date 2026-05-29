<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendRobotCommandRequest extends FormRequest
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
            'command' => ['required', 'string', Rule::in(['START', 'STOP', 'RESET'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'command.required' => 'Choose a robot command to send.',
            'command.in' => 'The selected robot command is not supported.',
        ];
    }
}
