<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use App\QueryBuilders\NotificationQueryBuilder;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return ApiResponse::success(NotificationQueryBuilder::fromRequest($request));
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        $user->unreadNotifications->markAsRead();

        return ApiResponse::success([
            'unread_count' => 0,
        ], 'All notifications marked as read');
    }
}
