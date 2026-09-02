<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class CommentCreatedNotification extends Notification
{
    protected $comment;
    protected $task;

    public function __construct($comment, $task)
    {
        $this->comment = $comment;
        $this->task = $task;
    }

    public function via($notifiable)
    {
        return ['database','broadcast'];
    }

    public function toArray($notifiable)
    {
        return [
            'comment_id' => $this->comment->id,
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'comment' => $this->comment->comment,
        ];
    }

    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
