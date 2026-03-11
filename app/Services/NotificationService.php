<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\UserActionNotification;

class NotificationService
{
    public function notifyUserAction(User $actor, User $target, string $action, array $meta = []): void
    {
        $recipients = collect([$actor, $target])->unique('id');

        foreach ($recipients as $recipient) {
            $recipient->notify(new UserActionNotification(
                $actor,
                $target,
                $action,
                $meta
            ));
        }
    }
}
