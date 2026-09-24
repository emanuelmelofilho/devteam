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
            WorkerCredential::query()->where('expires_at', '<', now())->delete();
            $credentialToken = null;
            if (AgentProviderSetting::apiKeyFor($provider)) {
                $credentialToken = Str::random(80);
                WorkerCredential::create([
                    'task_id' => $task->id,
                    'purpose' => WorkerCredential::PURPOSE_PROVIDER_API_KEY,
                    'token_hash' => hash('sha256', $credentialToken),
                    'expires_at' => now()->addMinutes(5),
                ]);
            }
            $repositoryCredentialToken = null;
            $project = $task->project;
            if ($project?->source_type === 'repository' && $project->repository_auth_type === 'https' && filled($project->repository_password)) {
                $repositoryCredentialToken = Str::random(80);
                WorkerCredential::create([
                    'task_id' => $task->id,
                    'purpose' => WorkerCredential::PURPOSE_REPOSITORY_PASSWORD,
                    'token_hash' => hash('sha256', $repositoryCredentialToken),
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
                'project_source_type' => $task->project?->source_type ?? 'local',
                'project_repository_provider' => $task->project?->repository_provider,
                'project_repository_url' => $task->project?->repository_url,
                'project_repository_auth_type' => $task->project?->repository_auth_type,
                'project_repository_username' => $task->project?->repository_username,
                'project_repository_ssh_key_path' => $task->project?->repository_ssh_key_path,
                'repository_credential_token' => $repositoryCredentialToken,
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
            if (isset($repositoryCredentialToken) && $repositoryCredentialToken) {
                WorkerCredential::where('token_hash', hash('sha256', $repositoryCredentialToken))->delete();
            }
            $execution->delete();
            report($error);

            return false;
        }
    }
}
