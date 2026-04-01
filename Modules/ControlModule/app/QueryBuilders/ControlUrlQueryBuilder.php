<?php

namespace Modules\ControlModule\QueryBuilders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\ControlModule\Models\ControlUrl;

class ControlUrlQueryBuilder
{
    private static function applyControlCounts(Builder $query): Builder
    {
        $includeTrashed = method_exists($query, 'removedScopes')
            && array_key_exists('Illuminate\\Database\\Eloquent\\SoftDeletingScope', $query->removedScopes());

        return $query
            ->select('control_urls.*')
            ->selectRaw("
                CASE
                    WHEN LOWER(COALESCE(control_urls.input_type, '')) LIKE '%analog%'
                        AND control_urls.max_value IS NOT NULL
                    THEN 1
                    ELSE 0
                END AS analog_count
            ")
            ->withCount([
                'jsonCommands as command_count' => function ($jsonCommandsQuery) use ($includeTrashed) {
                    if ($includeTrashed) {
                        $jsonCommandsQuery->withTrashed();
                    }
                },
            ]);
    }

    public static function fromRequest(Request $request)
    {
        $perPage = $request->integer('per_page', 10);
        $includes = self::parseIncludes($request);
        $query = self::applyControlCounts(self::buildQuery($request, $includes));
        $results = $query->orderByDesc('created_at')->paginate($perPage);

        if ($includes->contains('analog_signal') || $includes->contains('analogsignal')) {
            $results->through(function (ControlUrl $controlUrl) {
                return $controlUrl->append('analog_signal');
            });
        }

        return $results;
    }

    public static function buildQuery(Request $request, $includes = null): Builder
    {
        $query = ControlUrl::query();

        $includes = $includes ?? self::parseIncludes($request);
        $includeAll = $includes->contains('all');
        $onlyTrashed = $includes->contains('trashed');

        if ($onlyTrashed) {
            $query->onlyTrashed();
        } elseif ($includeAll) {
            $query->withTrashed();
        }

        $relations = [];

        if ($includes->contains('gateway')) {
            $relations['node'] = function ($nodeQuery) {
                $nodeQuery->withTrashed()->with([
                    'gateway' => fn ($gatewayQuery) => $gatewayQuery->withTrashed(),
                ]);
            };
        } elseif ($includes->contains('node')) {
            $relations['node'] = fn ($nodeQuery) => $nodeQuery->withTrashed();
        }

        if ($includes->contains('json_commands') || $includes->contains('jsonCommands')) {
            $relations['jsonCommands'] = fn ($jsonCommandsQuery) => $includeAll || $onlyTrashed
                ? $jsonCommandsQuery->withTrashed()
                : $jsonCommandsQuery;
        }

        if ($relations) {
            $query->with($relations);
        }

        if ($request->has('name')) {
            $query->where('name', $request->query('name'));
        }

        if ($request->has('input_type')) {
            $query->where('input_type', $request->query('input_type'));
        }

        if ($request->has('node_id')) {
            $query->where('node_id', $request->query('node_id'));
        }

        if ($request->has('search')) {
            $keyword = (string) $request->query('search');
            $query->where(function ($searchQuery) use ($keyword) {
                $searchQuery->where('name', 'like', "%{$keyword}%")
                    ->orWhere('url', 'like', "%{$keyword}%");
            });
        }

        return $query;
    }

    private static function parseIncludes(Request $request)
    {
        return collect(explode(',', (string) $request->query('include', '')))
            ->map(fn ($include) => strtolower(trim((string) $include)))
            ->filter()
            ->unique()
            ->values();
    }
}
