<?php

namespace Tests\Feature;

use App\Models\AgentExecution;
use App\Models\AiModelPrice;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkerCredential;
use App\Services\AgentQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentExecutionMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_creates_and_report_finalizes_an_agent_execution_with_cost(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'App', 'directory' => '/app']);
        AiModelPrice::create([
            'model' => 'test-model', 'input_price_per_million' => 1.0, 'output_price_per_million' => 2.0, 'valid_from' => now()->subDay(),
        ]);
        $task = Task::create([
            'project_id' => $project->id, 'title' => 'Com métricas', 'lane' => 'in_progress',
            'assigned_agent' => 'developer_worker', 'branch' => 'agent/task-999',
        ]);

        app(AgentQueueService::class)->dispatch($task, 'test-token');

        $this->assertDatabaseHas('agent_executions', [
            'task_id' => $task->id, 'agent' => 'developer', 'attempt' => 1, 'status' => 'running',
        ]);
        $execution = AgentExecution::where('task_id', $task->id)->firstOrFail();

        $this->actingAs($user, 'sanctum')->postJson("/api/kanban/tasks/{$task->id}/report", [
            'execution_id' => $execution->id,
            'outcome' => 'success',
            'summary' => 'Implementado.',
            'branch' => $task->branch,
            'model' => 'test-model',
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        ])->assertOk()->assertJsonPath('task.lane', 'review');

        $execution->refresh();
        $this->assertSame('success', $execution->status);
        $this->assertSame(1500, $execution->total_tokens);
        $this->assertEqualsWithDelta(0.002, (float) $execution->estimated_cost, 0.0001);
        $this->assertNotNull($execution->duration_ms);

        // A second developer execution for the same task increments the attempt counter.
        $secondExecutionId = AgentExecution::create([
            'task_id' => $task->id, 'agent' => 'developer', 'status' => 'running', 'started_at' => now(),
            'attempt' => AgentExecution::where('task_id', $task->id)->where('agent', 'developer')->max('attempt') + 1,
        ])->id;
        $this->assertDatabaseHas('agent_executions', ['id' => $secondExecutionId, 'task_id' => $task->id, 'agent' => 'developer', 'attempt' => 2]);
    }

    public function test_dispatch_issues_a_repository_credential_only_for_https_repository_projects_with_a_password(): void
    {
        $repositoryWithPassword = Project::create([
            'name' => 'Repo', 'source_type' => 'repository', 'repository_provider' => 'github',
            'repository_url' => 'https://github.com/acme/app.git', 'repository_auth_type' => 'https',
            'repository_username' => 'bot', 'repository_password' => 'segredo',
        ]);
        $repositoryWithoutPassword = Project::create([
            'name' => 'Repo público', 'source_type' => 'repository', 'repository_provider' => 'github',
            'repository_url' => 'https://github.com/acme/public.git', 'repository_auth_type' => 'none',
        ]);
        $local = Project::create(['name' => 'Local', 'source_type' => 'local', 'directory' => '/app']);

        foreach ([$repositoryWithPassword, $repositoryWithoutPassword, $local] as $project) {
            $task = Task::create(['project_id' => $project->id, 'title' => 'Tarefa', 'lane' => 'in_progress', 'assigned_agent' => 'developer_worker']);
            app(AgentQueueService::class)->dispatch($task, 'test-token');
        }

        $this->assertSame(1, WorkerCredential::where('purpose', WorkerCredential::PURPOSE_REPOSITORY_PASSWORD)->count());
    }
}
