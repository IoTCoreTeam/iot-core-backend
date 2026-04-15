<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\QueryBuilders\SystemLogQueryBuilder;

class SystemLogController extends Controller
{
    public function index(Request $request)
    {
        return SystemLogQueryBuilder::fromRequest($request);
    }

    public function countByWeekAndLevel(Request $request)
    {
        return response()->json(SystemLogQueryBuilder::countByWeekAndLevel());
    }

    public function countTopActions(Request $request)
    {
        $days = max(1, $request->integer('days', 7));
        $limit = max(1, $request->integer('limit', 6));

        return response()->json(SystemLogQueryBuilder::countTopActions($days, $limit));
    }

    public function countControlUrlOutcomes(Request $request)
    {
        $hours = max(1, $request->integer('hours', 12));
        $bucket = (string) $request->query('bucket', 'hour');

        return response()->json(
            SystemLogQueryBuilder::countControlUrlExecutionOutcomes($hours, $bucket)
        );
    }
}
