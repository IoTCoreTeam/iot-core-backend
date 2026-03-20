<?php

namespace App\QueryBuilders;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class UserQueryBuilder
{
    public static function fromRequest(Request $request)
    {
        $perPage = $request->integer('per_page', 20);
        $query = self::buildQuery($request);

        return $query->paginate($perPage);
    }

    public static function buildQuery(Request $request): Builder
    {
        $query = User::query();

        if ($request->has('id')) {
            return $query->where('id', $request->query('id'));
        }

        if ($request->has('search')) {
            $keyword = $request->query('search');

            return self::applySearch($query, $keyword);
        }

        return $query;
    }

    private static function applySearch(Builder $query, string $keyword): Builder
    {
        return $query->where(function (Builder $sub) use ($keyword) {
            $sub->where('name', 'like', "%{$keyword}%")
                ->orWhere('email', 'like', "%{$keyword}%")
                ->orWhere('role', 'like', "%{$keyword}%");
        });
    }

    public static function countByRole(): array
    {
        $knownRoles = ['admin', 'engineer', 'user'];

        $rawCounts = User::query()
            ->selectRaw('LOWER(role) as role_key, COUNT(*) as total')
            ->groupByRaw('LOWER(role)')
            ->pluck('total', 'role_key');

        $roleCounts = collect($knownRoles)
            ->mapWithKeys(fn ($role) => [$role => (int) ($rawCounts[$role] ?? 0)])
            ->all();

        $totalUsers = (int) $rawCounts->sum();
        $knownRolesTotal = array_sum($roleCounts);

        return [
            'role_counts' => $roleCounts,
            'other' => max(0, $totalUsers - $knownRolesTotal),
            'total' => $totalUsers,
        ];
    }
}
