<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AgentExecution;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentStepHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['name' => 'Ana']);
        $this->project = Project::create(['name' => 'Loja', 'directory' => '/loja']);
    }

    /** A task waiting on `$agent`, with a running execution for it. */
    private function running(string $lane, string $agentWorker, string $role): array
    {
        $task = Task::create(['project_id' => $this->project->id, 'title' => 'Corrigir carrinho', 'lane' => $lane, 'assigned_agent' => $agentWorker]);
        $execution = AgentExecution::create([
            'task_id' => $task->id, 'agent' => $role, 'provider' => 'claude', 'attempt' => 1, 'status' => 'running', 'started_at' => now(),
        ]);

        return [$task, $execution];
    }

    private function report(Task $task, AgentExecution $execution, array $extra = [])
    {
        return $this->actingAs($this->user, 'sanctum')->postJson("/api/kanban/tasks/{$task->id}/report", $extra + [
            'execution_id' => $execution->id, 'outcome' => 'success', 'summary' => 'Feito.',
        ]);
    }

    public function test_the_developer_solution_is_stored_and_linked_to_the_step_log(): void
    {
        [$task, $execution] = $this->running('in_progress', 'developer_worker', 'developer');

        $this->report($task, $execution, [
            'summary' => 'Carrinho passou a somar o frete.',
            'solution' => 'O total ignorava o frete; o cálculo foi movido para o service e coberto por teste.',
            'branch' => 'agent/task-1',
            'commit_hash' => 'a1b2c3d4e5f6',
            'commit_message' => "fix: somar frete no total\n\nO total do carrinho ignorava o frete.",
            'files' => [['path' => 'app/Cart.php', 'additions' => 12, 'deletions' => 3]],
            'patch' => "diff --git a/app/Cart.php b/app/Cart.php\n+ return \$total + \$shipping;",
        ])->assertOk()->assertJsonPath('task.lane', 'review');

        $execution->refresh();
        $this->assertSame('Carrinho passou a somar o frete.', $execution->summary);
        $this->assertStringContainsString('movido para o service', $execution->solution);
        $this->assertSame('app/Cart.php', $execution->files[0]['path']);
        $this->assertStringContainsString('somar frete', $execution->commit_message);

        $log = ActivityLog::where('task_id', $task->id)->firstOrFail();
        $this->assertSame($execution->id, $log->agent_execution_id);
        $this->assertSame('task.moved', $log->event);
    }

    public function test_a_step_that_keeps_the_task_in_the_same_lane_is_still_logged(): void
    {
        [$task, $execution] = $this->running('publishing', 'devops_worker', 'devops');

        $this->report($task, $execution, ['outcome' => 'publish_blocked', 'summary' => 'SSH recusou a chave.'])->assertOk();

        $log = ActivityLog::where('task_id', $task->id)->firstOrFail();
        $this->assertSame('task.step', $log->event);
        $this->assertSame('Etapa executada: DevOps', $log->title);
        $this->assertSame($execution->id, $log->agent_execution_id);
        $this->assertStringContainsString('SSH recusou a chave', $log->details);
    }

    public function test_the_list_carries_the_execution_without_the_patch_and_show_returns_it(): void
    {
        [$task, $execution] = $this->running('in_progress', 'developer_worker', 'developer');
        $this->report($task, $execution, ['solution' => 'Abordagem X.', 'patch' => '+ linha nova'])->assertOk();
        $log = ActivityLog::where('task_id', $task->id)->firstOrFail();

        $list = $this->getJson('/api/kanban/logs?scope=projects&per_page=50')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $log->id);
        $this->assertSame('developer', $row['execution']['agent']);
        $this->assertSame('claude', $row['execution']['provider']);
        $this->assertSame('Abordagem X.', $row['execution']['solution']);
        $this->assertEquals(1, $row['execution']['has_patch']);
        $this->assertArrayNotHasKey('patch', $row['execution']);

        $this->getJson("/api/kanban/logs/{$log->id}")->assertOk()
            ->assertJsonPath('execution.patch', '+ linha nova')
            ->assertJsonPath('task.title', 'Corrigir carrinho')
            ->assertJsonPath('user.name', 'Ana');
    }

    public function test_logs_from_people_have_no_execution(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/kanban/projects', ['name' => 'Novo', 'source_type' => 'local', 'directory' => '/novo'])->assertCreated();

        $row = $this->getJson('/api/kanban/logs?scope=projects&per_page=50')->assertOk()->json('data.0');
        $this->assertNull($row['execution']);
    }

    public function test_result_details_are_validated(): void
    {
        [$task, $execution] = $this->running('in_progress', 'developer_worker', 'developer');

        $this->report($task, $execution, ['files' => [['additions' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('files.0.path');
        $this->report($task, $execution, ['patch' => str_repeat('x', 120001)])->assertUnprocessable()->assertJsonValidationErrors('patch');
    }
}
