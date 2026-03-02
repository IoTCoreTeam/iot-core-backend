<?php

namespace Modules\ControlModule\Services;

class WorkflowDefinitionService
{
    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function filter(array $definition): array
    {
        $nodes = [];
        $edges = [];

        foreach (($definition['nodes'] ?? []) as $node) {
            if (! is_array($node)) {
                continue;
            }
            $nodes[] = [
                'id' => $node['id'] ?? null,
                'type' => $node['type'] ?? 'default',
                'position' => $node['position'] ?? null,
                'data' => $node['data'] ?? null,
            ];
        }

        foreach (($definition['edges'] ?? []) as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $edges[] = [
                'id' => $edge['id'] ?? null,
                'type' => $edge['type'] ?? 'default',
                'source' => $edge['source'] ?? null,
                'target' => $edge['target'] ?? null,
                'label' => $edge['label'] ?? null,
                'data' => $edge['data'] ?? null,
            ];
        }

        return [
            'version' => $definition['version'] ?? 1,
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }
}
