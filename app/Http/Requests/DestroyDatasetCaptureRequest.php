<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DestroyDatasetCaptureRequest extends FormRequest
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
            'category' => ['required', 'string', Rule::in(StoreDatasetCaptureRequest::CATEGORIES)],
            'filename' => ['required', 'string', 'regex:/^[a-z0-9_]+\.jpg$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.required' => 'Specify the dataset category of the capture to remove.',
            'category.in' => 'Choose one of the supported dataset capture categories.',
            'filename.required' => 'Specify the capture filename to remove.',
            'filename.regex' => 'The capture filename is invalid.',
        ];
    }
}
