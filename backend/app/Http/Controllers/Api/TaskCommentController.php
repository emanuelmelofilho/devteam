<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\WhatsappSetting;
use App\Services\WhatsAppService;
use App\Notifications\CommentCreatedNotification;
use App\Models\User;

class TaskCommentController extends Controller
{
    public function index(Task $task)
    {
        return $task->comments()->with('user')->get();
    }

    public function store(Request $request, Task $task)
    {
        $data = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'comment' => 'required|string',
        ]);

        $comment = new TaskComment(array_merge($data, ['task_id' => $task->id]));
        $comment->save();

        // Create database and broadcast notifications for admin and assignee
        $adminUser = User::where('email', 'emanuelmelofilho@gmail.com')->first();
        if ($adminUser) {
            $adminUser->notify(new CommentCreatedNotification($comment, $task));
        }

        if ($task->assignee_id) {
            $assigneeUser = User::find($task->assignee_id);
            if ($assigneeUser) {
                $assigneeUser->notify(new CommentCreatedNotification($comment, $task));
            }
        }

        // Notify via WhatsApp if configured
        $setting = WhatsappSetting::latest()->first();
        if ($setting && $setting->api_url && $setting->phone) {
            $service = new WhatsAppService($setting->api_url, $setting->client_token);
            $service->sendMessage($setting->phone, "Comentário em {$task->title}: {$comment->comment}");
        }

        return $comment;
    }
}
