<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FilterRobotLogsRequest extends FormRequest
{
    protected $redirectRoute = 'robot.dashboard.logs';

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
            'robot_id' => ['nullable', 'string', 'max:50'],
            'trash_type' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', Rule::in(['robot_sensor', 'laptop_camera', 'robot_camera'])],
            'from' => ['bail', 'nullable', 'date_format:Y-m-d'],
            'to' => ['bail', 'nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', Rule::in([15, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['from', 'to']) || ! $this->filled(['from', 'to'])) {
                return;
            }

            if (CarbonImmutable::parse($this->input('to'))->lessThan(CarbonImmutable::parse($this->input('from')))) {
                $validator->errors()->add('to', 'The end date must be on or after the start date.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'robot_id.string' => 'Enter a single robot ID.',
            'robot_id.max' => 'Robot IDs must be 50 characters or fewer.',
            'trash_type.string' => 'Enter a single waste category.',
            'trash_type.max' => 'Waste categories must be 100 characters or fewer.',
            'source.in' => 'Choose a supported record source.',
            'source.string' => 'Choose a single record source.',
            'from.date_format' => 'Enter a valid start date in YYYY-MM-DD format.',
            'to.date_format' => 'Enter a valid end date in YYYY-MM-DD format.',
            'per_page.in' => 'Choose 15, 25, or 50 records per page.',
            'per_page.integer' => 'Choose a valid number of records per page.',
            'page.integer' => 'Choose a valid page number.',
        ];
    }
}
