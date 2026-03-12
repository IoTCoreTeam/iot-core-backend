<?php

namespace Modules\ControlModule\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkflowRequest extends FormRequest
{
    public const FLOW_CONSTANTS = [
        'maxStartNodes' => 1,
        'maxEndNodes' => 1,
        'maxConditionBranches' => 2,
        'maxOutgoingForNonCondition' => 1,
    ];

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
            'name' => ['required', 'string', 'max:255'],
            'definition' => ['nullable', 'array'],
            'control_definition' => ['nullable', 'array'],
            'control_definition.version' => ['nullable', 'integer'],
            'control_definition.nodes' => ['nullable', 'array'],
            'control_definition.nodes.*.id' => ['required_with:control_definition.nodes', 'string'],
            'control_definition.nodes.*.type' => ['required_with:control_definition.nodes', 'string', 'in:start,action,condition,end'],
            'control_definition.edges' => ['nullable', 'array'],
            'control_definition.edges.*.source' => ['required_with:control_definition.edges', 'string'],
            'control_definition.edges.*.target' => ['required_with:control_definition.edges', 'string'],
            'control_definition.edges.*.branch' => ['nullable', 'string', 'in:true,false'],
            'status' => ['sometimes', 'string', 'in:approved,inactive'],
        ];
    }

    public function withValidator($validator): void
    {
        // Ghi chú: withValidator được Laravel gọi tự động sau khi tạo validator từ rules(),
        // dùng để bổ sung logic kiểm tra tùy biến cho control_definition.
        $validator->after(function ($validator) {
            $control = $this->input('control_definition');
            if (!is_array($control)) {
                return;
            }

            $nodes = $control['nodes'] ?? null;
            $edges = $control['edges'] ?? null;
            if (!is_array($nodes) || !is_array($edges)) {
                return;
            }

            $nodeById = [];
            $startCount = 0;
            $endCount = 0;
            foreach ($nodes as $node) {
                $id = $node['id'] ?? null;
                $type = $node['type'] ?? null;
                if (!is_string($id) || $id === '' || !is_string($type)) {
                    continue;
                }
                $nodeById[$id] = $type;
                if ($type === 'start') {
                    $startCount++;
                } elseif ($type === 'end') {
                    $endCount++;
                }
            }

            if ($startCount !== self::FLOW_CONSTANTS['maxStartNodes'] || $endCount !== self::FLOW_CONSTANTS['maxEndNodes']) {
                $validator->errors()->add('control_definition', 'Flow must have exactly one Start and one End node.');
                return;
            }

            $outgoingBySource = [];
            foreach ($edges as $edge) {
                $source = $edge['source'] ?? null;
                $target = $edge['target'] ?? null;
                if (!is_string($source) || $source === '' || !is_string($target) || $target === '') {
                    continue;
                }
                $outgoingBySource[$source][] = $edge;
            }

            foreach ($outgoingBySource as $sourceId => $outgoing) {
                $sourceType = $nodeById[$sourceId] ?? null;
                if ($sourceType === null) {
                    continue;
                }
                if ($sourceType === 'end') {
                    $validator->errors()->add('control_definition', 'End node cannot connect.');
                    return;
                }
                if ($sourceType !== 'condition' && count($outgoing) > self::FLOW_CONSTANTS['maxOutgoingForNonCondition']) {
                    $validator->errors()->add('control_definition', 'Each node can only connect to one other node.');
                    return;
                }
                if ($sourceType === 'condition') {
                    if (count($outgoing) > self::FLOW_CONSTANTS['maxConditionBranches']) {
                        $validator->errors()->add('control_definition', 'Condition node can only have two branches.');
                        return;
                    }
                    $branches = [];
                    foreach ($outgoing as $edge) {
                        $branch = $edge['branch'] ?? null;
                        if ($branch === null) {
                            continue;
                        }
                        $branches[] = $branch;
                    }
                    $branchCounts = array_count_values($branches);
                    if (($branchCounts['true'] ?? 0) > 1 || ($branchCounts['false'] ?? 0) > 1) {
                        $validator->errors()->add('control_definition', 'Condition node can only have one true and one false branch.');
                        return;
                    }
                }
            }

            $startId = null;
            $endId = null;
            foreach ($nodeById as $id => $type) {
                if ($type === 'start') {
                    $startId = $id;
                } elseif ($type === 'end') {
                    $endId = $id;
                }
            }
            if ($startId === null || $endId === null) {
                return;
            }

            $adjacency = [];
            foreach ($edges as $edge) {
                $source = $edge['source'] ?? null;
                $target = $edge['target'] ?? null;
                if (!is_string($source) || !is_string($target)) {
                    continue;
                }
                $adjacency[$source][] = $target;
            }

            $visited = [];
            $queue = [$startId];
            $found = false;
            while (!empty($queue)) {
                $current = array_shift($queue);
                if ($current === $endId) {
                    $found = true;
                    break;
                }
                if (isset($visited[$current])) {
                    continue;
                }
                $visited[$current] = true;
                foreach ($adjacency[$current] ?? [] as $next) {
                    if (!isset($visited[$next])) {
                        $queue[] = $next;
                    }
                }
            }

            if (!$found) {
                $validator->errors()->add('control_definition', 'Flow must connect Start to End.');
            }
        });
    }
}
