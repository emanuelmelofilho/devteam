<?php

namespace Tests\Feature;

use App\Models\AgentExecution;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricsSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_computes_first_pass_rate_and_insights(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'App', 'directory' => '/app']);

        // 4 tasks that passed on the first developer attempt.
        for ($i = 0; $i < 4; $i++) {
            $task = Task::create(['project_id' => $project->id, 'title' => "Ok {$i}", 'lane' => 'published', 'version' => '1.0.0']);
            AgentExecution::create([
                'task_id' => $task->id, 'agent' => 'developer', 'attempt' => 1, 'status' => 'success',
                'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(9), 'duration_ms' => 60000,
            ]);
        }

        // 1 task that needed a second developer attempt (rework).
        $reworked = Task::create(['project_id' => $project->id, 'title' => 'Rework', 'lane' => 'published', 'version' => '1.0.0']);
        AgentExecution::create([
            'task_id' => $reworked->id, 'agent' => 'developer', 'attempt' => 1, 'status' => 'bug',
            'started_at' => now()->subMinutes(20), 'finished_at' => now()->subMinutes(19), 'duration_ms' => 60000,
        ]);
        AgentExecution::create([
            'task_id' => $reworked->id, 'agent' => 'developer', 'attempt' => 2, 'status' => 'success',
            'started_at' => now()->subMinutes(15), 'finished_at' => now()->subMinutes(14), 'duration_ms' => 60000,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/kanban/metrics/summary?days=30')
            ->assertOk();

        $response->assertJsonPath('tasks_done', 5);
        $this->assertEquals(80.0, $response->json('first_pass_rate'));
        $this->assertEquals(20.0, $response->json('rework_rate'));
        $this->assertNotNull($response->json('insights'));
    }
}
