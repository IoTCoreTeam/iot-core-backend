<?php

namespace Modules\ControlModule\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNodeRequest extends FormRequest
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
            'external_id' => 'required|string|max:255',
            'gateway_id' => 'required|string|max:255',
            'name' => 'nullable|string|max:255',
            'mac_address' => 'nullable|string|max:255',
            'ip_address' => 'nullable|string|max:45',
            'type' => 'required|string|in:controller,sensor,node',
        ];
    }
}
