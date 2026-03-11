<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\User;

class UserActionNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        protected User $actor,
        protected User $target,
        protected string $action,
        protected array $meta = []
    )
    {
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $actionLabel = $this->action === 'user.delete'
            ? 'deleted'
            : ($this->action === 'user.update' ? 'updated' : $this->action);

        return [
            'action' => $this->action,
            'title' => 'User ' . ucfirst($actionLabel),
            'message' => "{$this->actor->name} {$actionLabel} user {$this->target->name}",
            'actor' => [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ],
            'target' => [
                'id' => $this->target->id,
                'name' => $this->target->name,
                'email' => $this->target->email,
            ],
            'meta' => $this->meta,
        ];
    }
}
