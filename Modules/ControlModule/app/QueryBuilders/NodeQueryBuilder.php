<?php

namespace Modules\ControlModule\QueryBuilders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\ControlModule\Models\Gateway;
use Modules\ControlModule\Models\Node;

class NodeQueryBuilder
{
    public static function fromRequest(Request $request)
    {
        $perPage = $request->integer('per_page', 5);
        $query = self::buildQuery($request);

        return $query->paginate($perPage);
    }

    public static function buildQuery(Request $request): Builder
    {
        $query = Node::query();

        $query = self::applyNodeFilters($query, $request);

        if ($request->has('type')) {
            $type = strtolower((string) $request->query('type', ''));
            if ($type !== '') {
                $query->where('type', $type);
            }
        }

        $query->with('managedAreas:id,name');

        return $query;
    }

    /**
     * @return array{
     *     gateways: array<int, string>,
     *     nodes: array<int, string>,
     *     gateway_nodes: array<string, array<int, string>>,
     *     node_details: array<int, array{external_id: string|null, name: string|null}>
     * }
     */
    public static function getActiveDevicesPayload(): array
    {
        $gateways = Gateway::pluck('external_id')
            ->filter()
            ->values()
            ->all();
        $nodes = Node::pluck('external_id')
            ->filter()
            ->values()
            ->all();
        $nodeDetails = Node::query()
            ->get(['external_id', 'name'])
            ->filter(fn ($node) => ! empty($node->external_id))
            ->map(fn ($node) => [
                'external_id' => $node->external_id,
                'name' => $node->name,
            ])
            ->values()
            ->all();

        return [
            'gateways' => $gateways,
            'nodes' => $nodes,
            'node_details' => $nodeDetails,
            'gateway_nodes' => self::buildGatewayNodesMap($gateways),
        ];
    }

    /**
     * @return array{
     *     gateways: array<int, string>,
     *     nodes: array<int, string>,
     *     gateway_nodes: array<string, array<int, string>>
     * }
     */
    public static function getWhitelistPayload(): array
    {
        $gateways = Gateway::pluck('external_id')
            ->filter()
            ->values()
            ->all();
        $nodes = Node::pluck('external_id')
            ->filter()
            ->values()
            ->all();

        return [
            'gateways' => $gateways,
            'nodes' => $nodes,
            'gateway_nodes' => self::buildGatewayNodesMap($gateways),
        ];
    }

    /**
     * @param array<int, string> $gatewayExternalIds
     * @return array<string, array<int, string>>
     */
    private static function buildGatewayNodesMap(array $gatewayExternalIds): array
    {
        $map = [];
        foreach ($gatewayExternalIds as $gatewayExternalId) {
            $map[$gatewayExternalId] = [];
        }

        if (empty($gatewayExternalIds)) {
            return $map;
        }

        $nodes = Node::whereHas('gateway')
            ->with('gateway:id,external_id')
            ->get(['id', 'gateway_id', 'external_id']);

        foreach ($nodes as $node) {
            $gatewayExternalId = $node->gateway?->external_id;
            if (! $gatewayExternalId || ! array_key_exists($gatewayExternalId, $map)) {
                continue;
            }
            $map[$gatewayExternalId][] = $node->external_id;
        }

        foreach ($map as $gatewayExternalId => $nodeExternalIds) {
            $map[$gatewayExternalId] = array_values(array_unique($nodeExternalIds));
        }

        return $map;
    }

    private static function applyNodeFilters(Builder $query, Request $request, bool $includeSearch = true): Builder
    {
        if ($request->has('external_id')) {
            return $query->where('external_id', $request->query('external_id'));
        }

        if ($request->has('name')) {
            return $query->where('name', $request->query('name'));
        }

        if ($request->has('gateway_id')) {
            return $query->where('gateway_id', $request->query('gateway_id'));
        }

        if ($includeSearch && $request->has('search')) {
            $keyword = $request->query('search');

            return self::applyNodeSearch($query, $keyword);
        }

        return $query;
    }

    private static function applyNodeSearch(Builder $query, string $keyword): Builder
    {
        return $query->where('name', 'like', "%{$keyword}%")
            ->orWhere('external_id', 'like', "%{$keyword}%");
    }

}
