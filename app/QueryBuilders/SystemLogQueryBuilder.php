<?php

namespace App\QueryBuilders;

use App\Models\SystemLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SystemLogQueryBuilder
{
    public static function fromRequest(Request $request)
    {
        $perPage = $request->integer('per_page', 15);
        $query = self::buildQuery($request);

        return $query->paginate($perPage);
    }

    public static function buildQuery(Request $request): Builder
    {
        $query = SystemLog::query()->latest();

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->query('action'));
        }

        if ($request->filled('level')) {
            $query->where('level', $request->query('level'));
        }

        if ($request->filled('message')) {
            $query->where('message', $request->query('message'));
        }

        if ($request->filled('ip_address')) {
            $query->where('ip_address', $request->query('ip_address'));
        }

        if ($request->filled('context')) {
            $query->where('context', $request->query('context'));
        }

        if ($request->filled('keyword')) {
            self::applyKeyword($query, $request->query('keyword'));
        }

        if ($request->filled('start') || $request->filled('end')) {
            self::applyTime($query, $request->query('start'), $request->query('end'));
        }

        return $query;
    }

    private static function applyKeyword(Builder $query, string $keyword): Builder
    {
        return $query->where(function (Builder $sub) use ($keyword) {
            $sub->where('message', 'like', "%{$keyword}%")
                ->orWhere('action', 'like', "%{$keyword}%")
                ->orWhere('context', 'like', "%{$keyword}%");
        });
    }

    private static function applyTime(Builder $query, ?string $start, ?string $end): Builder
    {
        if ($start && $end) {
            return $query->whereBetween('created_at', [$start, $end]);
        }

        if ($start) {
            $query->where('created_at', '>=', $start);
        }

        if ($end) {
            $query->where('created_at', '<=', $end);
        }

        return $query;
    }

    public static function countByWeekAndLevel(int $weeks = 5): array
    {
        $weeks = max(1, $weeks);
        $startDate = Carbon::now()->subWeeks($weeks)->startOfWeek();

        $logs = SystemLog::query()
            ->selectRaw('YEARWEEK(created_at, 1) as week, level, count(*) as count')
            ->where('created_at', '>=', $startDate)
            ->groupBy('week', 'level')
            ->orderBy('week')
            ->get();

        $categories = [];
        $currentDate = $startDate->copy();
        for ($i = 0; $i <= $weeks; $i++) {
            $yearWeek = $currentDate->format('oW');
            $categories[$yearWeek] = 'Week ' . $currentDate->week;
            $currentDate->addWeek();
        }

        $seriesData = [
            'warning' => array_fill_keys(array_keys($categories), 0),
            'error' => array_fill_keys(array_keys($categories), 0),
            'info' => array_fill_keys(array_keys($categories), 0),
        ];

        foreach ($logs as $log) {
            $yearWeek = $log->week;
            if (!isset($categories[$yearWeek])) {
                continue;
            }
            $level = strtolower((string) $log->level);
            if (isset($seriesData[$level])) {
                $seriesData[$level][$yearWeek] = (int) $log->count;
            }
        }

        return [
            'categories' => array_values($categories),
            'series' => [
                ['name' => 'Error', 'data' => array_values($seriesData['error'])],
                ['name' => 'Warning', 'data' => array_values($seriesData['warning'])],
                ['name' => 'Info', 'data' => array_values($seriesData['info'])],
            ],
        ];
    }

    public static function countTopActions(int $days = 7, int $limit = 6): array
    {
        $days = max(1, $days);
        $limit = max(1, $limit);
        $startDate = Carbon::now()->subDays($days)->startOfDay();

        $rows = SystemLog::query()
            ->selectRaw('action, COUNT(*) as total')
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('action')
            ->where('action', '!=', '')
            ->groupBy('action')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        return [
            'categories' => $rows->pluck('action')->values()->all(),
            'series' => [
                [
                    'name' => 'Count',
                    'data' => $rows->pluck('total')->map(fn ($value) => (int) $value)->values()->all(),
                ],
            ],
            'days' => $days,
        ];
    }

    public static function countControlUrlExecutionOutcomes(int $hours = 12, string $bucket = 'hour'): array
    {
        $hours = max(1, $hours);
        $bucket = in_array($bucket, ['minute', 'hour'], true) ? $bucket : 'hour';

        $startDate = Carbon::now()->subHours($hours);
        $endDate = Carbon::now();
        $format = $bucket === 'minute' ? '%Y-%m-%d %H:%i:00' : '%Y-%m-%d %H:00:00';

        $rows = SystemLog::query()
            ->selectRaw("DATE_FORMAT(created_at, '{$format}') as time_bucket, action, COUNT(*) as total")
            ->where('created_at', '>=', $startDate)
            ->whereIn('action', ['control_url.executed', 'control_url.execute_failed'])
            ->groupBy('time_bucket', 'action')
            ->orderBy('time_bucket')
            ->get();

        $bucketCounts = [];
        foreach ($rows as $row) {
            $key = (string) ($row->time_bucket ?? '');
            if ($key === '') {
                continue;
            }
            if (!isset($bucketCounts[$key])) {
                $bucketCounts[$key] = ['success' => 0, 'failed' => 0];
            }
            $count = (int) ($row->total ?? 0);
            if ($row->action === 'control_url.executed') {
                $bucketCounts[$key]['success'] += $count;
            } elseif ($row->action === 'control_url.execute_failed') {
                $bucketCounts[$key]['failed'] += $count;
            }
        }

        $timeline = [];
        $cursor = $startDate->copy();
        while ($cursor->lessThanOrEqualTo($endDate)) {
            $key = $bucket === 'minute' ? $cursor->format('Y-m-d H:i:00') : $cursor->format('Y-m-d H:00:00');
            $success = (int) ($bucketCounts[$key]['success'] ?? 0);
            $failed = (int) ($bucketCounts[$key]['failed'] ?? 0);

            $timeline[] = [
                'bucket' => $cursor->copy()->toIso8601String(),
                'success' => $success,
                'failed' => $failed,
                'total' => $success + $failed,
            ];

            $bucket === 'minute' ? $cursor->addMinute() : $cursor->addHour();
        }

        $totalSuccess = array_sum(array_column($timeline, 'success'));
        $totalFailed = array_sum(array_column($timeline, 'failed'));

        return [
            'bucket' => $bucket,
            'hours' => $hours,
            'totals' => [
                'success' => $totalSuccess,
                'failed' => $totalFailed,
                'total' => $totalSuccess + $totalFailed,
            ],
            'buckets' => $timeline,
        ];
    }
}
