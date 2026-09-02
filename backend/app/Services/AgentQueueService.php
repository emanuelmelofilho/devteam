<?php

namespace App\Services;

use App\Models\Task;
use App\Models\CodexSetting;
use App\Models\WorkerCredential;
use Illuminate\Support\Str;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class AgentQueueService
{
    private const QUEUES = [
        'developer_worker' => 'tasks_developer',
        'tester_worker' => 'tasks_tester',
        'devops_worker' => 'tasks_devops',
    ];

    public function dispatch(Task $task, ?string $apiToken): bool
    {
        $queue = self::QUEUES[$task->assigned_agent] ?? null;
        if (!$queue || !$apiToken) return false;

        try {
            $connection = new AMQPStreamConnection(
                env('RABBITMQ_HOST', 'rabbitmq'),
                (int) env('RABBITMQ_PORT', 5672),
                env('RABBITMQ_USER', 'guest'),
                env('RABBITMQ_PASSWORD', env('RABBITMQ_PASS', 'guest')),
            );
            $channel = $connection->channel();
            $channel->queue_declare($queue, false, true, false, false);
            $task->loadMissing('project');
            $credentialToken = null;
            if (CodexSetting::query()->exists()) {
                $credentialToken = Str::random(80);
                WorkerCredential::query()->where('expires_at', '<', now())->delete();
                WorkerCredential::create([
                    'task_id' => $task->id,
                    'token_hash' => hash('sha256', $credentialToken),
                    'expires_at' => now()->addMinutes(5),
                ]);
            }
            $payload = [
                'task_id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'lane' => $task->lane,
                'assigned_agent' => $task->assigned_agent,
                'project_id' => $task->project_id,
                'project_name' => $task->project?->name,
                'project_directory' => $task->project?->directory,
                'api_token' => $apiToken,
                'credential_token' => $credentialToken,
            ];
            $message = new AMQPMessage(json_encode($payload), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]);
            $channel->basic_publish($message, '', $queue);
            $channel->close();
            $connection->close();
            return true;
        } catch (Throwable $error) {
            if (isset($credentialToken) && $credentialToken) {
                WorkerCredential::where('token_hash', hash('sha256', $credentialToken))->delete();
            }
            report($error);
            return false;
        }
    }
}
