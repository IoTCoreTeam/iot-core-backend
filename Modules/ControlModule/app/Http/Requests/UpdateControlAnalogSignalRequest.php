<?php

namespace Modules\ControlModule\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateControlAnalogSignalRequest extends FormRequest
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
            'control_url_id' => 'sometimes|uuid|exists:control_urls,id',
            'min_value' => 'nullable|numeric',
            'max_value' => 'sometimes|numeric',
            'unit' => 'sometimes|string|max:255',
            'signal_type' => 'sometimes|string|max:255',
            'resolution_bits' => 'sometimes|integer|min:1|max:255',
        ];
    }
}
