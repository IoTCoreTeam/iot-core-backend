<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\UserActionNotification;
use App\Notifications\WorkflowActionNotification;
use Modules\ControlModule\Models\Workflow;

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

    public function notifyWorkflowAction(User $actor, Workflow $workflow, string $action, array $meta = []): void
    {
        $actor->notify(new WorkflowActionNotification(
            $actor,
            $workflow,
            $action,
            $meta
        ));
    }
}
