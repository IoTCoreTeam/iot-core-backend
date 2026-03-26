<?php

namespace App\Http\Controllers;

use App\Models\SystemLog;
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
        return response()->json(SystemLog::countByWeekAndLevel());
    }

    public function countTopActions(Request $request)
    {
        $days = max(1, $request->integer('days', 7));
        $limit = max(1, $request->integer('limit', 6));

        return response()->json(SystemLog::countTopActions($days, $limit));
    }
}
