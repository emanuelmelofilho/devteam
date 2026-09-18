<?php

namespace App\Services;

use Agents\Orchestrator\TaskRouter;
use App\Models\AgentExecution;
use App\Models\AgentProviderSetting;
use App\Models\Task;
use App\Models\WorkerCredential;
use Illuminate\Support\Str;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class AgentQueueService
{
    public function __construct(private readonly OrchestratorService $orchestrator) {}

    public function dispatch(Task $task, ?string $apiToken): bool
    {
        $queue = $this->orchestrator->queueFor((string) $task->assigned_agent);
        if (! $queue || ! $apiToken) {
            return false;
        }

        $role = TaskRouter::roleFor((string) $task->assigned_agent);
        $provider = AgentProviderSetting::current();
        $execution = AgentExecution::create([
            'task_id' => $task->id,
            'agent' => $role,
            'provider' => $provider,
            'attempt' => AgentExecution::where('task_id', $task->id)->where('agent', $role)->max('attempt') + 1,
            'status' => 'running',
            'started_at' => now(),
            'branch' => $task->branch,
        ]);

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
            if (AgentProviderSetting::apiKeyFor($provider)) {
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
                'execution_id' => $execution->id,
                'attempt' => $execution->attempt,
                'title' => $task->title,
                'description' => $task->description,
                'lane' => $task->lane,
                'assigned_agent' => $task->assigned_agent,
                'branch' => $task->branch,
                'project_id' => $task->project_id,
                'project_name' => $task->project?->name,
                'project_directory' => $task->project?->directory,
                'project_stack' => [
                    'frontend' => $task->project?->frontend,
                    'backend' => $task->project?->backend,
                    'database' => $task->project?->database,
                ],
                'provider' => $provider,
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
            $execution->delete();
            report($error);

            return false;
        }
    }
}
