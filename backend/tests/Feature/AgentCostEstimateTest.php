<?php

namespace Tests\Feature;

use App\Models\AgentExecution;
use App\Models\AiModelPrice;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentCostEstimateTest extends TestCase
{
    use RefreshDatabase;

    private function price(string $model, float $input, ?float $cached, float $output): void
    {
        AiModelPrice::create([
            'model' => $model,
            'input_price_per_million' => $input,
            'cached_input_price_per_million' => $cached,
            'output_price_per_million' => $output,
            'valid_from' => now()->subDay(),
        ]);
    }

    /** Sends a report for a fresh execution and returns the finalized execution. */
    private function report(array $payload)
    {
        $task = Task::create([
            'project_id' => Project::create(['name' => 'App', 'directory' => '/app'])->id,
            'title' => 'Custo', 'lane' => 'in_progress', 'assigned_agent' => 'developer_worker',
        ]);
        $execution = AgentExecution::create([
            'task_id' => $task->id, 'agent' => 'developer', 'attempt' => 1, 'status' => 'running', 'started_at' => now(),
        ]);

        return [$this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/kanban/tasks/{$task->id}/report", $payload + [
                'execution_id' => $execution->id, 'outcome' => 'success', 'summary' => 'Ok.',
            ]), $execution->fresh()];
    }

    public function test_cached_tokens_are_billed_at_the_cached_rate_and_dated_models_match_by_prefix(): void
    {
        $this->price('claude-haiku-4-5', 1.0, 0.10, 5.0);

        [$response] = $this->report([
            'model' => 'claude-haiku-4-5-20251001',
            'usage' => ['input_tokens' => 1_000_000, 'cached_input_tokens' => 900_000, 'output_tokens' => 100_000],
        ]);
        $response->assertOk();

        // 100k uncached x $1 + 900k cached x $0.10 + 100k output x $5, per million.
        $this->assertEqualsWithDelta(0.69, (float) AgentExecution::firstOrFail()->estimated_cost, 0.000001);
    }

    public function test_the_most_specific_model_row_wins(): void
    {
        $this->price('gpt-5', 1.25, 0.125, 10.0);
        $this->price('gpt-5-mini', 0.25, 0.025, 2.0);

        $this->report(['model' => 'gpt-5-mini-2026-01-01', 'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 1_000_000]])[0]->assertOk();

        $this->assertEqualsWithDelta(2.25, (float) AgentExecution::firstOrFail()->estimated_cost, 0.000001);
    }

    public function test_a_price_without_a_cached_rate_bills_cached_tokens_as_input(): void
    {
        $this->price('flat-model', 2.0, null, 4.0);

        $this->report(['model' => 'flat-model', 'usage' => ['input_tokens' => 1_000_000, 'cached_input_tokens' => 500_000, 'output_tokens' => 0]])[0]->assertOk();

        $this->assertEqualsWithDelta(2.0, (float) AgentExecution::firstOrFail()->estimated_cost, 0.000001);
    }

    public function test_the_cost_reported_by_the_worker_overrides_the_estimate(): void
    {
        $this->price('claude-haiku-4-5', 1.0, 0.10, 5.0);

        $this->report([
            'model' => 'claude-haiku-4-5-20251001', 'cost_usd' => 0.1234,
            'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 1_000_000],
        ])[0]->assertOk();

        $this->assertEqualsWithDelta(0.1234, (float) AgentExecution::firstOrFail()->estimated_cost, 0.000001);
    }

    public function test_unknown_models_have_no_estimate_and_negative_costs_are_rejected(): void
    {
        $this->report(['model' => 'modelo-desconhecido', 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]])[0]->assertOk();
        $this->assertNull(AgentExecution::firstOrFail()->estimated_cost);

        $this->report(['cost_usd' => -1])[0]->assertUnprocessable()->assertJsonValidationErrors('cost_usd');
    }
}
