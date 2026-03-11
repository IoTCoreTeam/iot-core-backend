<?php

namespace Modules\MapModule\Http\Requests\Route;

use Illuminate\Foundation\Http\FormRequest;

class FindNearestPathRequest extends FormRequest
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
            'start_node_id' => ['required', 'string'],
            'end_node_id' => ['required', 'string', 'different:start_node_id'],
            'nodes' => ['required', 'array', 'min:1'],
            'nodes.*.id' => ['required', 'string'],
            'nodes.*.status' => ['required', 'string', 'in:online,offline'],
            'nodes.*.connected_nodes' => ['nullable', 'array'],
            'nodes.*.connected_nodes.*' => ['string'],
        ];
    }
}
