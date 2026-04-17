<?php

namespace Modules\ControlModule\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ControlModule\Models\ControlUrl;

class UpdateWorkflowRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'definition' => ['nullable', 'array'],
            'control_definition' => ['nullable', 'array'],
            'status' => ['sometimes', 'required', 'string', 'in:approved,inactive'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $control = $this->input('control_definition');
            if (!is_array($control)) {
                return;
            }

            $nodes = $control['nodes'] ?? null;
            if (!is_array($nodes)) {
                return;
            }

            $actionControlUrlMap = [];
            foreach ($nodes as $index => $node) {
                if (!is_array($node)) {
                    continue;
                }

                $type = strtolower((string) ($node['type'] ?? ''));
                if ($type !== 'action') {
                    continue;
                }

                $nodeId = (string) ($node['id'] ?? ("index_" . $index));
                $controlUrlId = trim((string) ($node['control_url_id'] ?? ''));
                if ($controlUrlId === '') {
                    $validator->errors()->add(
                        'control_definition',
                        "Action node {$nodeId} is missing control_url_id."
                    );
                    continue;
                }

                if (!preg_match('/^[0-9a-fA-F-]{36}$/', $controlUrlId)) {
                    $validator->errors()->add(
                        'control_definition',
                        "Action node {$nodeId} has invalid control_url_id format."
                    );
                    continue;
                }

                $actionControlUrlMap[$nodeId] = $controlUrlId;
            }

            if ($validator->errors()->isNotEmpty() || empty($actionControlUrlMap)) {
                return;
            }

            $existingIds = ControlUrl::query()
                ->whereIn('id', array_values($actionControlUrlMap))
                ->pluck('id')
                ->all();
            $existingLookup = array_fill_keys($existingIds, true);

            foreach ($actionControlUrlMap as $nodeId => $controlUrlId) {
                if (isset($existingLookup[$controlUrlId])) {
                    continue;
                }
                $validator->errors()->add(
                    'control_definition',
                    "Action node {$nodeId} references missing control_url_id {$controlUrlId}."
                );
            }
        });
    }
}
