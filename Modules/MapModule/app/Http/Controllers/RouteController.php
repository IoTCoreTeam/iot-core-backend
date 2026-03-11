<?php

namespace Modules\MapModule\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use Modules\MapModule\Http\Requests\Route\FindNearestPathRequest;
use SplQueue;
use Throwable;

class RouteController extends Controller
{
    public function findNearestPath(FindNearestPathRequest $request)
    {
        try {
            $startId = (string) $request->input('start_node_id');
            $endId = (string) $request->input('end_node_id');
            $nodes = $request->input('nodes', []);

            $onlineNodes = [];
            foreach ($nodes as $node) {
                $status = strtolower((string) ($node['status'] ?? ''));
                if ($status !== 'online') {
                    continue;
                }
                $id = (string) ($node['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $onlineNodes[$id] = $node;
            }

            if (! array_key_exists($startId, $onlineNodes)) {
                return ApiResponse::error('Start node is offline or missing.', 400);
            }

            if (! array_key_exists($endId, $onlineNodes)) {
                return ApiResponse::error('End node is offline or missing.', 400);
            }

            $adjacency = [];
            foreach ($onlineNodes as $id => $node) {
                $adjacency[$id] = $adjacency[$id] ?? [];
                $connected = $node['connected_nodes'] ?? [];
                if (! is_array($connected)) {
                    $connected = [];
                }
                foreach ($connected as $neighbor) {
                    $neighborId = (string) $neighbor;
                    if ($neighborId === '' || ! array_key_exists($neighborId, $onlineNodes)) {
                        continue;
                    }
                    $adjacency[$id][$neighborId] = true;
                    $adjacency[$neighborId][$id] = true;
                }
            }

            $queue = new SplQueue();
            $queue->enqueue($startId);
            $prev = [$startId => null];

            while (! $queue->isEmpty()) {
                $current = $queue->dequeue();
                if ($current === $endId) {
                    break;
                }
                $neighbors = array_keys($adjacency[$current] ?? []);
                foreach ($neighbors as $neighbor) {
                    if (array_key_exists($neighbor, $prev)) {
                        continue;
                    }
                    $prev[$neighbor] = $current;
                    $queue->enqueue($neighbor);
                }
            }

            if (! array_key_exists($endId, $prev)) {
                return ApiResponse::success([
                    'path' => [],
                    'start_node_id' => $startId,
                    'end_node_id' => $endId,
                ], 'No path found.');
            }

            $path = [];
            $cursor = $endId;
            while ($cursor !== null) {
                $path[] = $cursor;
                $cursor = $prev[$cursor] ?? null;
            }
            $path = array_reverse($path);

            return ApiResponse::success([
                'path' => $path,
                'start_node_id' => $startId,
                'end_node_id' => $endId,
            ], 'Path found.');
        } catch (Throwable $e) {
            return ApiResponse::error('Failed to compute nearest path.', 500, $e->getMessage());
        }
    }
}
