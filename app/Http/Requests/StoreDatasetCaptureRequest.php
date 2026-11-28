<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDatasetCaptureRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    public const CATEGORIES = [
        'background',
        'trash',
        'plastic_bottle',
        'other_plastic',
        'plastic_wrapper',
        'paper',
        'metal_can',
        'aluminum_can',
    ];

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
            'category' => ['required', 'string', Rule::in(self::CATEGORIES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.required' => 'Choose a dataset category before capturing.',
            'category.in' => 'Choose one of the supported dataset capture categories.',
        ];
    }
}
