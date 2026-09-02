<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;

class TaskUpdatedNotification extends Notification
{
    protected $task;
    protected $event;

    public function __construct($task, string $event)
    {
        $this->task = $task;
        $this->event = $event;
    }

    public function via($notifiable)
    {
        return ['database','broadcast'];
    }

    public function toArray($notifiable)
    {
        return [
            'task_id' => $this->task->id,
            'title' => $this->task->title,
            'lane' => $this->task->lane,
            'event' => $this->event,
            'message' => $this->event === 'started'
                ? "A atividade {$this->task->title} foi iniciada."
                : "A atividade {$this->task->title} foi finalizada.",
        ];
    }

    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
