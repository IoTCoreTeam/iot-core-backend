<?php

namespace Modules\ControlModule\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommandController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(1, min(200, $request->integer('per_page', 20)));
        $page = max(1, $request->integer('page', 1));

        $jsonQuery = DB::table('control_json_commands as cjc')
            ->join('control_urls as cu', 'cu.id', '=', 'cjc.control_url_id')
            ->leftJoin('nodes as n', 'n.id', '=', 'cu.node_id')
            ->leftJoin('gateways as g', 'g.id', '=', 'n.gateway_id')
            ->selectRaw("
                cjc.id as id,
                cjc.control_url_id as control_url_id,
                'json_command' as command_type,
                cjc.name as command_name,
                cjc.command as command_payload,
                null as min_value,
                null as max_value,
                null as unit,
                null as signal_type,
                null as resolution_bits,
                cjc.created_at as created_at,
                cjc.updated_at as updated_at,
                cu.id as control_url_ref_id,
                cu.node_id as node_id,
                cu.controller_id as controller_id,
                cu.name as control_url_name,
                cu.url as control_url,
                cu.input_type as control_url_input_type,
                n.external_id as node_external_id,
                g.external_id as gateway_external_id
            ");

        $analogQuery = DB::table('control_analog_signals as cas')
            ->join('control_urls as cu', 'cu.id', '=', 'cas.control_url_id')
            ->leftJoin('nodes as n', 'n.id', '=', 'cu.node_id')
            ->leftJoin('gateways as g', 'g.id', '=', 'n.gateway_id')
            ->selectRaw("
                cas.id as id,
                cas.control_url_id as control_url_id,
                'analog_signal' as command_type,
                cas.signal_type as command_name,
                null as command_payload,
                cas.min_value as min_value,
                cas.max_value as max_value,
                cas.unit as unit,
                cas.signal_type as signal_type,
                cas.resolution_bits as resolution_bits,
                cas.created_at as created_at,
                cas.updated_at as updated_at,
                cu.id as control_url_ref_id,
                cu.node_id as node_id,
                cu.controller_id as controller_id,
                cu.name as control_url_name,
                cu.url as control_url,
                cu.input_type as control_url_input_type,
                n.external_id as node_external_id,
                g.external_id as gateway_external_id
            ");

        $union = $jsonQuery->unionAll($analogQuery);
        $query = DB::query()->fromSub($union, 'commands');

        if ($request->filled('control_url_id')) {
            $query->where('control_url_id', (string) $request->query('control_url_id'));
        }

        if ($request->filled('type')) {
            $query->where('command_type', (string) $request->query('type'));
        }

        if ($request->filled('input_type')) {
            $query->where('control_url_input_type', (string) $request->query('input_type'));
        }

        if ($request->filled('search')) {
            $keyword = '%' . trim((string) $request->query('search')) . '%';
            $query->where(function ($searchQuery) use ($keyword) {
                $searchQuery
                    ->where('command_name', 'like', $keyword)
                    ->orWhere('control_url_name', 'like', $keyword)
                    ->orWhere('control_url', 'like', $keyword)
                    ->orWhere('controller_id', 'like', $keyword)
                    ->orWhere('node_external_id', 'like', $keyword);
            });
        }

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $rows = $query
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->forPage($page, $perPage)
            ->get();

        $data = $rows->map(function ($row) {
            $commandPayload = $row->command_payload;
            if (is_string($commandPayload)) {
                $decoded = json_decode($commandPayload, true);
                $commandPayload = json_last_error() === JSON_ERROR_NONE ? $decoded : $commandPayload;
            }

            return [
                'id' => (string) $row->id,
                'control_url_id' => (string) $row->control_url_id,
                'type' => (string) $row->command_type,
                'name' => $row->command_name ? (string) $row->command_name : null,
                'command' => $commandPayload,
                'min_value' => $row->min_value,
                'max_value' => $row->max_value,
                'unit' => $row->unit,
                'signal_type' => $row->signal_type,
                'resolution_bits' => $row->resolution_bits !== null ? (int) $row->resolution_bits : null,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                'control_url' => [
                    'id' => (string) $row->control_url_ref_id,
                    'node_id' => $row->node_id ? (string) $row->node_id : null,
                    'node_external_id' => $row->node_external_id ? (string) $row->node_external_id : null,
                    'gateway_external_id' => $row->gateway_external_id ? (string) $row->gateway_external_id : null,
                    'controller_id' => $row->controller_id ? (string) $row->controller_id : null,
                    'name' => (string) $row->control_url_name,
                    'url' => (string) $row->control_url,
                    'input_type' => (string) $row->control_url_input_type,
                ],
                'detail' => [
                    'json_command' => $row->command_type === 'json_command'
                        ? [
                            'id' => (string) $row->id,
                            'name' => $row->command_name ? (string) $row->command_name : null,
                            'command' => $commandPayload,
                        ]
                        : null,
                    'analog_signal' => $row->command_type === 'analog_signal'
                        ? [
                            'id' => (string) $row->id,
                            'min_value' => $row->min_value,
                            'max_value' => $row->max_value,
                            'unit' => $row->unit,
                            'signal_type' => $row->signal_type,
                            'resolution_bits' => $row->resolution_bits !== null ? (int) $row->resolution_bits : null,
                        ]
                        : null,
                ],
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'total' => $total,
        ]);
    }
}
