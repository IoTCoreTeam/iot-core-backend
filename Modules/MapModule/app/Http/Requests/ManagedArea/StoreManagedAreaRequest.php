<?php

namespace Modules\MapModule\Http\Requests\ManagedArea;

use Illuminate\Foundation\Http\FormRequest;

class StoreManagedAreaRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120', 'unique:managed_areas,name'],
            'geom_type' => ['required', 'string', 'in:polygon,rectangle'],
            'geometry' => ['required', 'array'],
            'bbox' => ['nullable', 'array'],
        ];
    }
}
