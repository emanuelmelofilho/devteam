<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AgentQueueService;
use App\Notifications\TaskUpdatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Tests\TestCase;

class TaskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_task_requires_an_agent_and_starts_in_todo(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'App', 'directory' => '/app']);
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/kanban/tasks', [
            'project_id' => $project->id, 'title' => 'Nova tela', 'description' => str_repeat('Descrição detalhada. ', 32), 'assigned_agent' => 'developer_worker',
        ]);
        $response->assertCreated()->assertJsonPath('lane', 'todo')->assertJsonPath('branch', 'developer');
    }

    public function test_task_accepts_a_short_request_but_still_requires_description(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'App', 'directory' => '/app']);

        $this->actingAs($user, 'sanctum')->postJson('/api/kanban/tasks', [
            'project_id' => $project->id,
            'title' => 'Nova tela',
            'description' => 'Descrição curta',
            'assigned_agent' => 'developer_worker',
        ])->assertCreated()->assertJsonPath('description', 'Descrição curta');

        $this->postJson('/api/kanban/tasks', [
            'project_id' => $project->id,
            'title' => 'Nova tela',
            'assigned_agent' => 'developer_worker',
        ])->assertUnprocessable()->assertJsonValidationErrors('description');
    }

    public function test_workflow_assigns_agents_and_requires_summary_and_version(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'App', 'directory' => '/app']);
        $task = Task::create(['project_id' => $project->id, 'title' => 'Fluxo', 'lane' => 'todo', 'assigned_agent' => 'developer_worker']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/kanban/tasks/{$task->id}", ['lane' => 'in_progress'])
            ->assertOk()->assertJsonPath('assigned_agent', 'developer_worker');
        $this->patchJson("/api/kanban/tasks/{$task->id}", ['lane' => 'review'])->assertUnprocessable();
        $this->patchJson("/api/kanban/tasks/{$task->id}", ['lane' => 'review', 'work_summary' => 'Layout ajustado'])
            ->assertOk()->assertJsonPath('assigned_agent', 'tester_worker');
        $this->patchJson("/api/kanban/tasks/{$task->id}", ['lane' => 'publishing'])
            ->assertOk()->assertJsonPath('assigned_agent', 'devops_worker');
        $this->patchJson("/api/kanban/tasks/{$task->id}", ['lane' => 'published'])->assertUnprocessable();
        $this->patchJson("/api/kanban/tasks/{$task->id}", ['lane' => 'published', 'version' => '1.2.3'])
            ->assertOk()->assertJsonPath('version', '1.2.3');

        Notification::assertSentTo($user, TaskUpdatedNotification::class, function ($notification) use ($user) {
            return in_array($notification->toArray($user)['event'], ['started', 'finished'], true);
        });
        $this->assertDatabaseHas('activity_logs', ['task_id' => $task->id, 'event' => 'task.started']);
        $this->assertDatabaseHas('activity_logs', ['task_id' => $task->id, 'event' => 'task.finished']);
    }

    public function test_user_can_filter_the_explained_logs_by_project(): void
    {
        $user = User::factory()->create();
        $first = Project::create(['name' => 'Primeiro', 'directory' => '/first']);
        $second = Project::create(['name' => 'Segundo', 'directory' => '/second']);
        \App\Models\ActivityLog::create(['project_id' => $first->id, 'user_id' => $user->id, 'event' => 'project.created', 'title' => 'Primeiro', 'details' => 'Detalhes do primeiro projeto.']);
        \App\Models\ActivityLog::create(['project_id' => $second->id, 'user_id' => $user->id, 'event' => 'project.created', 'title' => 'Segundo', 'details' => 'Detalhes do segundo projeto.']);

        $this->actingAs($user, 'sanctum')->getJson("/api/kanban/logs?project_id={$first->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.project.name', 'Primeiro');
    }

    public function test_devteam_and_project_logs_are_separate_and_paginated(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Projeto isolado', 'directory' => '/isolated']);
        \App\Models\ActivityLog::create(['scope' => 'devteam', 'event' => 'devteam.updated', 'title' => 'Devteam', 'details' => 'Alteração interna.']);
        \App\Models\ActivityLog::create(['scope' => 'project', 'project_id' => $project->id, 'event' => 'project.updated', 'title' => 'Projeto', 'details' => 'Alteração do projeto.']);

        $this->actingAs($user, 'sanctum')->getJson('/api/kanban/logs?scope=devteam&per_page=5')
            ->assertOk()->assertJsonPath('data.0.scope', 'devteam');
        $this->getJson('/api/kanban/logs?scope=projects&per_page=5')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.project.name', 'Projeto isolado');
    }

    public function test_only_todo_tasks_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'App', 'directory' => '/app']);
        $todo = Task::create(['project_id' => $project->id, 'title' => 'Pode excluir', 'lane' => 'todo']);
        $active = Task::create(['project_id' => $project->id, 'title' => 'Não pode excluir', 'lane' => 'in_progress']);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/kanban/tasks/{$active->id}")
            ->assertUnprocessable();
        $this->deleteJson("/api/kanban/tasks/{$todo->id}")->assertNoContent();

        $this->assertDatabaseMissing('tasks', ['id' => $todo->id]);
        $this->assertDatabaseHas('tasks', ['id' => $active->id]);
    }

    public function test_an_existing_task_can_be_assigned_to_an_agent(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'App', 'directory' => '/app']);
        $task = Task::create(['project_id' => $project->id, 'title' => 'Sem agente', 'lane' => 'in_progress']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/kanban/tasks/{$task->id}", [
            'assigned_agent' => 'developer_worker',
        ])->assertOk()->assertJsonPath('assigned_agent', 'developer_worker');
    }

    public function test_an_active_task_can_be_requeued_to_the_correct_agent(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'App', 'directory' => '/app']);
        $task = Task::create(['project_id' => $project->id, 'title' => 'Tentar novamente', 'lane' => 'in_progress']);
        $this->mock(AgentQueueService::class, function (MockInterface $mock) {
            $mock->shouldReceive('dispatch')->once()->andReturnTrue();
        });

        $this->actingAs($user, 'sanctum')->withToken('test-token')
            ->postJson("/api/kanban/tasks/{$task->id}/retry")
            ->assertAccepted()->assertJsonPath('task.assigned_agent', 'developer_worker');
    }

    public function test_a_published_task_cannot_be_requeued(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'App', 'directory' => '/app']);
        $task = Task::create(['project_id' => $project->id, 'title' => 'Publicada', 'lane' => 'published']);

        $this->actingAs($user, 'sanctum')->postJson("/api/kanban/tasks/{$task->id}/retry")
            ->assertUnprocessable();
    }

    public function test_a_blocked_publication_can_return_to_development(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'App', 'directory' => '/app']);
        $task = Task::create(['project_id' => $project->id, 'title' => 'Falha na publicação', 'lane' => 'publishing', 'assigned_agent' => 'devops_worker']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/kanban/tasks/{$task->id}", ['lane' => 'in_progress'])
            ->assertOk()->assertJsonPath('assigned_agent', 'developer_worker');
    }
}
