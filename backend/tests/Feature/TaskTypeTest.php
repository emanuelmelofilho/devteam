<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTypeTest extends TestCase
{
    use RefreshDatabase;

    private function create(array $extra = [])
    {
        return $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/kanban/tasks', $extra + [
            'project_id' => Project::create(['name' => 'App', 'directory' => '/app'])->id,
            'title' => 'Tarefa',
            'description' => 'Descrição.',
            'assigned_agent' => 'developer_worker',
        ]);
    }

    public function test_each_change_type_is_stored_with_the_task(): void
    {
        foreach (Task::TYPES as $type) {
            $this->create(['type' => $type])->assertCreated()->assertJsonPath('type', $type);
        }

        $this->assertSame(Task::TYPES, Task::orderBy('id')->pluck('type')->all());
    }

    public function test_a_task_without_a_type_is_a_fix(): void
    {
        $this->create()->assertCreated()->assertJsonPath('type', 'fix');
    }

    public function test_an_unknown_type_is_rejected(): void
    {
        $this->create(['type' => 'refactor'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertSame(0, Task::count());
    }
}
