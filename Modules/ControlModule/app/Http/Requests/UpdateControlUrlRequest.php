<?php

namespace Modules\ControlModule\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateControlUrlRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'controller_id' => ['sometimes', 'required', 'string', 'max:255'],
            'node_id' => ['sometimes', 'required', 'string', 'max:255'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'url' => ['sometimes', 'required', 'string', 'max:2048'],
            'input_type' => ['sometimes', 'required', 'string', 'max:100'],
            'min_value' => ['sometimes', 'nullable', 'numeric', 'lte:max_value'],
            'max_value' => ['sometimes', 'nullable', 'numeric', 'gte:min_value'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:255'],
            'signal_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'resolution_bits' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:255'],
        ];
    }
}
