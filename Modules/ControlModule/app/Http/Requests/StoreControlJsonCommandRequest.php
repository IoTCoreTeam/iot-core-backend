<?php

namespace Modules\ControlModule\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreControlJsonCommandRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $command = $this->input('command');

        if ((!is_string($name) || trim($name) === '') && is_array($command)) {
            $legacyName = $command['name'] ?? null;
            if (is_string($legacyName) && trim($legacyName) !== '') {
                $this->merge(['name' => trim($legacyName)]);
            }
        }
    }

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
            'command' => 'required',
        ];
    }
}
