<?php

namespace Modules\ControlModule\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteControlUrlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'state' => ['sometimes', 'nullable'],
            'value' => ['sometimes', 'nullable', 'numeric'],
            'action_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'device' => ['sometimes', 'nullable', 'string', 'max:255'],
            'json_command_id' => ['sometimes', 'nullable', 'uuid', 'exists:control_json_commands,id'],
            'json_command_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'command' => [
                'sometimes',
                'array',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_array($value) || array_is_list($value)) {
                        $fail('The '.$attribute.' must be a JSON object.');
                    }
                },
            ],
            'command_payload' => [
                'sometimes',
                'array',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_array($value) || array_is_list($value)) {
                        $fail('The '.$attribute.' must be a JSON object.');
                    }
                },
            ],
            'command_overrides' => [
                'sometimes',
                'array',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_array($value) || array_is_list($value)) {
                        $fail('The '.$attribute.' must be a JSON object.');
                    }
                },
            ],
            'save_command_payload' => ['sometimes', 'boolean'],
            'wait_for_response' => ['sometimes', 'boolean'],
            'response_timeout_ms' => ['sometimes', 'integer', 'min:1000', 'max:300000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'url' => $this->filled('url') ? trim((string) $this->input('url')) : $this->input('url'),
            'action_type' => $this->filled('action_type') ? trim((string) $this->input('action_type')) : $this->input('action_type'),
            'device' => $this->filled('device') ? trim((string) $this->input('device')) : $this->input('device'),
            'json_command_id' => $this->filled('json_command_id') ? trim((string) $this->input('json_command_id')) : $this->input('json_command_id'),
            'json_command_name' => $this->filled('json_command_name') ? trim((string) $this->input('json_command_name')) : $this->input('json_command_name'),
        ]);
    }
}
