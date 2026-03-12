<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\User;
use Modules\ControlModule\Models\Workflow;

class WorkflowActionNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected User $actor,
        protected Workflow $workflow,
        protected string $action,
        protected array $meta = []
    )
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $actionLabel = match ($this->action) {
            'workflow.run' => 'ran',
            'workflow.run.completed' => 'completed',
            'workflow.run.failed' => 'failed',
            'workflow.stop' => 'stopped',
            default => $this->action,
        };

        $workflowName = $this->workflow->name ?? ('workflow #' . $this->workflow->id);

        $titleAction = match ($this->action) {
            'workflow.run' => 'Run',
            'workflow.run.completed' => 'Run Completed',
            'workflow.run.failed' => 'Run Failed',
            'workflow.stop' => 'Stop',
            default => $this->action,
        };

        return [
            'action' => $this->action,
            'title' => 'Workflow ' . $titleAction,
            'message' => "{$this->actor->name} {$actionLabel} {$workflowName}",
            'actor' => [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ],
            'workflow' => [
                'id' => $this->workflow->id,
                'name' => $this->workflow->name,
                'status' => $this->workflow->status ?? null,
            ],
            'meta' => $this->meta,
        ];
    }
}
