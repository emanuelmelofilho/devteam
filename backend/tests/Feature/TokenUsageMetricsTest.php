<?php

namespace Tests\Feature;

use App\Models\AgentExecution;
use App\Models\Project;
use App\Models\Task;
use App\Models\TokenGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenUsageMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->task = Task::create([
            'project_id' => Project::create(['name' => 'App', 'directory' => '/app'])->id,
            'title' => 'Tokens',
            'lane' => 'todo',
        ]);
    }

    private function execution(?string $provider, string $agent, ?int $tokens, $finishedAt, string $status = 'success'): void
    {
        AgentExecution::create([
            'task_id' => $this->task->id,
            'agent' => $agent,
            'provider' => $provider,
            'attempt' => 1,
            'status' => $status,
            'started_at' => $finishedAt ?? now(),
            'finished_at' => $finishedAt,
            'total_tokens' => $tokens,
        ]);
    }

    private function usage(string $provider, int $days = 7): array
    {
        $response = $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/kanban/metrics/summary?days={$days}")->assertOk();

        return [$response->json('token_usage.days'), collect($response->json('token_usage.providers'))->firstWhere('id', $provider)];
    }

    public function test_daily_usage_is_split_by_provider_and_agent_and_zero_filled(): void
    {
        $this->execution('codex', 'developer', 1000, now());
        $this->execution('claude', 'developer', 2000, now());
        $this->execution('claude', 'tester', 500, now());
        $this->execution('claude', 'devops', 300, now()->subDay());

        [$days, $claude] = $this->usage('claude');

        $this->assertCount(8, $days);
        $this->assertSame(now()->toDateString(), end($days));
        $this->assertSame(['developer', 'tester', 'devops'], array_keys($claude['series']));
        $this->assertSame('Claude (Anthropic)', $claude['label']);
        $this->assertSame(2800, $claude['total']);
        $this->assertSame([2000, 500, 0], [
            $claude['series']['developer'][7], $claude['series']['tester'][7], $claude['series']['devops'][7],
        ]);
        $this->assertSame(300, $claude['series']['devops'][6]);
        $this->assertSame(0, $claude['series']['developer'][0]);
        // Mean of the two days that had usage: (2500 + 300) / 2.
        $this->assertSame(1400, $claude['baseline']);

        [, $codex] = $this->usage('codex');
        $this->assertSame(1000, $codex['total']);
    }

    public function test_runs_without_tokens_or_outside_the_period_are_ignored_and_legacy_runs_count_as_codex(): void
    {
        $this->execution('claude', 'developer', null, null, 'running');
        $this->execution('claude', 'developer', 900, now()->subDays(40));
        $this->execution(null, 'tester', 400, now());

        [, $claude] = $this->usage('claude');
        $this->assertSame(0, $claude['total']);
        $this->assertNull($claude['baseline']);

        [, $codex] = $this->usage('codex');
        $this->assertSame(400, $codex['total']);
    }

    public function test_daily_goal_can_be_set_updated_and_cleared(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->putJson('/api/kanban/token-goals/claude', ['daily_tokens' => 500000])
            ->assertOk()->assertJsonPath('daily_goal', 500000);
        $this->putJson('/api/kanban/token-goals/claude', ['daily_tokens' => 750000])->assertOk();
        $this->assertSame(1, TokenGoal::count());

        [, $claude] = $this->usage('claude');
        $this->assertSame(750000, $claude['daily_goal']);
        [, $codex] = $this->usage('codex');
        $this->assertNull($codex['daily_goal']);

        $this->putJson('/api/kanban/token-goals/claude', ['daily_tokens' => null])
            ->assertOk()->assertJsonPath('daily_goal', null);
        $this->assertSame(0, TokenGoal::count());
    }

    public function test_daily_goal_validates_provider_and_value(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->putJson('/api/kanban/token-goals/gemini', ['daily_tokens' => 1000])->assertNotFound();
        $this->putJson('/api/kanban/token-goals/codex', ['daily_tokens' => 0])->assertUnprocessable();
        $this->putJson('/api/kanban/token-goals/codex', ['daily_tokens' => 'muito'])->assertUnprocessable();
        $this->putJson('/api/kanban/token-goals/codex', [])->assertUnprocessable();
    }
}
