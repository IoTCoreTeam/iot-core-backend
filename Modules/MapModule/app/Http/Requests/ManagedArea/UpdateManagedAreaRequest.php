<?php

namespace Modules\MapModule\Http\Requests\ManagedArea;

use Illuminate\Foundation\Http\FormRequest;

class UpdateManagedAreaRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120', 'unique:managed_areas,name,' . $this->route('id')],
            'geom_type' => ['sometimes', 'string', 'in:polygon,rectangle'],
            'geometry' => ['sometimes', 'array'],
            'bbox' => ['nullable', 'array'],
        ];
    }
}
