<?php

namespace Modules\ControlModule\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreControlJsonCommandRequest extends FormRequest
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
            'control_url_id' => 'required|uuid|exists:control_urls,id',
            'name' => 'required|string|max:255',
            'command' => [
                'required',
                'array',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_array($value) || array_is_list($value)) {
                        $fail('The '.$attribute.' must be a JSON object.');
                    }
                },
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->filled('name') ? trim((string) $this->input('name')) : $this->input('name'),
        ]);
    }
}
