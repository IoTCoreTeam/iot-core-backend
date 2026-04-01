<?php

namespace Modules\ControlModule\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreControlUrlRequest extends FormRequest
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
            'controller_id' => ['required', 'string', 'max:255'],
            'node_id' => ['required', 'uuid', 'exists:nodes,id'],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048'],
            'input_type' => ['required', 'string', 'max:100'],
            'min_value' => ['nullable', 'numeric', 'lte:max_value'],
            'max_value' => ['nullable', 'numeric', 'gte:min_value'],
            'unit' => ['nullable', 'string', 'max:255'],
            'signal_type' => ['nullable', 'string', 'max:255'],
            'resolution_bits' => ['nullable', 'integer', 'min:1', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'controller_id' => $this->filled('controller_id') ? trim((string) $this->input('controller_id')) : $this->input('controller_id'),
            'name' => $this->filled('name') ? trim((string) $this->input('name')) : $this->input('name'),
            'url' => $this->filled('url') ? trim((string) $this->input('url')) : $this->input('url'),
            'input_type' => $this->filled('input_type') ? trim((string) $this->input('input_type')) : $this->input('input_type'),
            'unit' => $this->filled('unit') ? trim((string) $this->input('unit')) : $this->input('unit'),
            'signal_type' => $this->filled('signal_type') ? trim((string) $this->input('signal_type')) : $this->input('signal_type'),
        ]);
    }
}
