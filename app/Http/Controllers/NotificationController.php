<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\ApiResponse;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $limit = max(1, min(50, (int) $request->integer('limit', 20)));

        $notifications = $user->notifications()
            ->orderByRaw('read_at is null desc')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'data' => $notification->data ?? [],
                    'read_at' => $notification->read_at,
                    'created_at' => $notification->created_at,
                ];
            });

        return ApiResponse::success([
            'items' => $notifications,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
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
