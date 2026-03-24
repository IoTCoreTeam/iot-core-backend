<?php

namespace Modules\ControlModule\Services;

class WorkflowRunDataHelper
{
    /**
     * @param array<int, mixed> $nodes
     * @return array<string, mixed>
     */
    public function indexNodes(array $nodes): array
    {
        $map = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = $node['id'] ?? null;
            if (! $id) {
                continue;
            }
            $map[$id] = $node;
        }

        return $map;
    }

    /**
     * @param array<int, mixed> $edges
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function indexEdges(array $edges): array
    {
        $map = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $source = $edge['source'] ?? null;
            if (! $source) {
                continue;
            }
            $map[$source][] = $edge;
        }

        return $map;
    }

    /**
     * @param array<int, mixed> $nodes
     */
    public function findNodeIdByType(array $nodes, string $type): ?string
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) === $type) {
                return $node['id'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $edgeMap
     */
    public function resolveNextNodeId(string $currentId, array $edgeMap, ?string $branch): ?string
    {
        $edges = $edgeMap[$currentId] ?? [];
        if (empty($edges)) {
            return null;
        }
        if (! $branch) {
            $edge = $edges[0] ?? null;

            return $edge['target'] ?? null;
        }
        foreach ($edges as $edge) {
            $edgeBranch = $edge['branch'] ?? null;
            if ($edgeBranch === $branch) {
                return $edge['target'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $deviceStatus
     * @return array<string, bool>
     */
    public function indexOnlineNodes(array $deviceStatus): array
    {
        $online = [];
        foreach ($deviceStatus as $gateway) {
            if (! is_array($gateway)) {
                continue;
            }
            $gatewayId = $gateway['id'] ?? $gateway['gateway_id'] ?? null;
            if (! $gatewayId) {
                continue;
            }
            $gatewayStatus = strtolower((string) ($gateway['status'] ?? ''));
            if ($gatewayStatus !== 'online') {
                continue;
            }
            $nodes = $gateway['nodes'] ?? [];
            if (! is_array($nodes)) {
                continue;
            }
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $nodeId = $node['id'] ?? $node['node_id'] ?? null;
                if (! $nodeId) {
                    continue;
                }
                $nodeStatus = strtolower((string) ($node['status'] ?? ''));
                if ($nodeStatus !== 'online') {
                    continue;
                }
                $key = $gatewayId . '::' . $nodeId;
                $online[$key] = true;
            }
        }

        return $online;
    }

    /**
     * @param array<int, mixed> $nodes
     * @return array<int, string>
     */
    public function collectActionControlUrls(array $nodes): array
    {
        $controlUrlIds = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) !== 'action') {
                continue;
            }
            $controlUrlId = $node['control_url_id'] ?? null;
            if (! $controlUrlId) {
                continue;
            }
            $controlUrlIds[(string) $controlUrlId] = true;
        }

        return array_keys($controlUrlIds);
    }

    public function mapMetricKey(string $metricKey): string
    {
        return match ($metricKey) {
            'soilMoisture' => 'soil',
            'soil_moisture' => 'soil',
            'airHumidity' => 'humidity',
            'air_humidity' => 'humidity',
            default => $metricKey,
        };
    }

    public function normalizeControlInputType(?string $inputType): ?string
    {
        $normalized = strtolower(trim((string) ($inputType ?? '')));
        if ($normalized === '') {
            return null;
        }
        if (str_contains($normalized, 'analog')) {
            return 'analog';
        }
        if (str_contains($normalized, 'digital') || str_contains($normalized, 'relay')) {
            return 'digital';
        }

        return $normalized;
    }
}

