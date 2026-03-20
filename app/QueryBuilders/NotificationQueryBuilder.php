<?php

namespace App\QueryBuilders;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;

class NotificationQueryBuilder
{
    public static function fromRequest(Request $request): array
    {
        $user = $request->user();
        $limit = self::resolveLimit($request);

        $items = self::buildQuery($request)
            ->limit($limit)
            ->get()
            ->map(static fn (DatabaseNotification $notification) => [
                'id' => $notification->id,
                'type' => $notification->type,
                'data' => $notification->data ?? [],
                'read_at' => $notification->read_at,
                'created_at' => $notification->created_at,
            ]);

        return [
            'items' => $items,
            'unread_count' => $user->unreadNotifications()->count(),
        ];
    }

    public static function buildQuery(Request $request): MorphMany
    {
        return $request->user()
            ->notifications()
            ->orderByRaw('read_at is null desc')
            ->orderByDesc('created_at');
    }

    private static function resolveLimit(Request $request): int
    {
        return max(1, min(50, (int) $request->integer('limit', 20)));
    }
}
