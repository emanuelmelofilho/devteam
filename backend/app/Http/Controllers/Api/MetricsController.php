<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentExecution;
use App\Models\AgentProviderSetting;
use App\Models\Task;
use App\Models\TokenGoal;
use App\Services\MetricsInsightService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MetricsController extends Controller
{
    public function __construct(private readonly MetricsInsightService $insights) {}

    public function summary(Request $request)
    {
        $days = max(1, min((int) $request->query('days', 30), 365));
        $projectId = $request->query('project_id');
        $since = now()->subDays($days);

        $taskQuery = fn () => Task::query()->when($projectId, fn ($q) => $q->where('project_id', $projectId));
        $executionQuery = fn () => AgentExecution::query()
            ->join('tasks', 'tasks.id', '=', 'agent_executions.task_id')
            ->when($projectId, fn ($q) => $q->where('tasks.project_id', $projectId))
            ->where('agent_executions.started_at', '>=', $since);

        $publishedIds = $taskQuery()->where('lane', 'published')->where('tasks.updated_at', '>=', $since)->pluck('tasks.id');
        $tasksDone = $publishedIds->count();

        // First pass rate / rework rate / avg retries: based on how many
        // developer attempts each task needed in the period.
        $devAttemptsByTask = $executionQuery()
            ->where('agent_executions.agent', 'developer')
            ->where('agent_executions.status', '!=', 'running')
            ->get(['agent_executions.task_id', 'agent_executions.attempt'])
            ->groupBy('task_id')
            ->map(fn ($rows) => (int) $rows->max('attempt'));
        $tasksWithDev = $devAttemptsByTask->count();
        $firstPassCount = $devAttemptsByTask->filter(fn ($maxAttempt) => $maxAttempt <= 1)->count();
        $firstPassRate = $tasksWithDev > 0 ? round($firstPassCount / $tasksWithDev * 100, 2) : null;
        $reworkRate = $tasksWithDev > 0 ? round((1 - $firstPassCount / $tasksWithDev) * 100, 2) : null;
        $avgRetries = $tasksWithDev > 0 ? round($devAttemptsByTask->map(fn ($maxAttempt) => max(0, $maxAttempt - 1))->avg(), 2) : null;

        // Escaped defects: tasks published in the period that later became
        // the origin_task_id of a new (bug) task.
        $escapedCount = $tasksDone > 0 ? Task::whereIn('origin_task_id', $publishedIds)->distinct('origin_task_id')->count('origin_task_id') : 0;
        $escapedDefectRate = $tasksDone > 0 ? round($escapedCount / $tasksDone * 100, 2) : null;

        // Average duration per agent, successful runs only.
        $durationRows = $executionQuery()
            ->where('agent_executions.status', 'success')
            ->whereNotNull('agent_executions.duration_ms')
            ->selectRaw('agent_executions.agent as agent, AVG(agent_executions.duration_ms) as avg_ms')
            ->groupBy('agent_executions.agent')
            ->pluck('avg_ms', 'agent');
        $avgDurationByAgent = collect(['developer', 'tester', 'devops'])
            ->mapWithKeys(fn ($agent) => [$agent => isset($durationRows[$agent]) ? (int) round($durationRows[$agent] / 1000) : null]);

        // Tokens and cost, overall and broken down per agent (useful to spot
        // e.g. a tester reading far more tokens than the change size justifies).
        $totals = $executionQuery()->selectRaw('
            SUM(agent_executions.input_tokens) as input_tokens,
            SUM(agent_executions.output_tokens) as output_tokens,
            SUM(agent_executions.total_tokens) as total_tokens,
            SUM(agent_executions.estimated_cost) as cost
        ')->first();
        $costTotal = $totals && $totals->cost !== null ? round((float) $totals->cost, 4) : null;
        $tokensPerTask = $tasksDone > 0 && $totals?->total_tokens ? (int) round($totals->total_tokens / $tasksDone) : null;
        $costPerTask = $tasksDone > 0 && $costTotal !== null ? round($costTotal / $tasksDone, 4) : null;

        $byAgentRows = $executionQuery()
            ->selectRaw('
                agent_executions.agent as agent,
                SUM(agent_executions.total_tokens) as total_tokens,
                SUM(agent_executions.estimated_cost) as cost
            ')
            ->groupBy('agent_executions.agent')
            ->get()
            ->keyBy('agent');
        $tokensByAgent = collect(['developer', 'tester', 'devops'])
            ->mapWithKeys(fn ($agent) => [$agent => isset($byAgentRows[$agent]) && $byAgentRows[$agent]->total_tokens !== null ? (int) $byAgentRows[$agent]->total_tokens : null]);
        $costByAgent = collect(['developer', 'tester', 'devops'])
            ->mapWithKeys(fn ($agent) => [$agent => isset($byAgentRows[$agent]) && $byAgentRows[$agent]->cost !== null ? round((float) $byAgentRows[$agent]->cost, 4) : null]);

        // Deploy outcomes.
        $deployExecutions = $executionQuery()
            ->where('agent_executions.agent', 'devops')
            ->where('agent_executions.status', '!=', 'running')
            ->get(['agent_executions.status', 'agent_executions.error_type']);
        $deployTotal = $deployExecutions->count();
        $deploySuccess = $deployExecutions->where('status', 'success')->count();
        $deploySuccessRate = $deployTotal > 0 ? round($deploySuccess / $deployTotal * 100, 2) : null;
        $failuresByType = $deployExecutions->where('status', '!=', 'success')
            ->groupBy(fn ($execution) => $execution->error_type ?: 'outro')
            ->map(fn ($group) => $group->count());

        // Lead time (creation -> last execution finished) vs processing time
        // (sum of execution durations) for tasks published in the period.
        $publishedTasks = Task::whereIn('id', $publishedIds)->get(['id', 'created_at'])->keyBy('id');
        $executionSummaryByTask = AgentExecution::whereIn('task_id', $publishedIds)
            ->selectRaw('task_id, SUM(duration_ms) as total_duration_ms, MAX(finished_at) as last_finished_at')
            ->groupBy('task_id')
            ->get()
            ->keyBy('task_id');
        $leadTimes = [];
        $processingTimes = [];
        foreach ($publishedTasks as $id => $task) {
            $summary = $executionSummaryByTask->get($id);
            if (! $summary || ! $summary->last_finished_at) {
                continue;
            }
            $leadTimes[] = (int) round(abs($task->created_at->diffInSeconds(Carbon::parse($summary->last_finished_at))));
            $processingTimes[] = (int) round(($summary->total_duration_ms ?? 0) / 1000);
        }
        $leadTimeAvg = count($leadTimes) > 0 ? (int) round(array_sum($leadTimes) / count($leadTimes)) : null;
        $processingTimeAvg = count($processingTimes) > 0 ? (int) round(array_sum($processingTimes) / count($processingTimes)) : null;

        // Daily first-pass-rate trend (developer's first attempt only).
        $history = $executionQuery()
            ->where('agent_executions.agent', 'developer')
            ->where('agent_executions.attempt', 1)
            ->whereNotNull('agent_executions.finished_at')
            ->selectRaw("DATE(agent_executions.finished_at) as day, SUM(CASE WHEN agent_executions.status = 'success' THEN 1 ELSE 0 END) as successes, COUNT(*) as total")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->day,
                'first_pass_rate' => $row->total > 0 ? round($row->successes / $row->total * 100, 1) : null,
            ]);

        $metricsForInsight = [
            'first_pass_rate' => $firstPassRate,
            'rework_rate' => $reworkRate,
            'escaped_defect_rate' => $escapedDefectRate,
            'deploy_success_rate' => $deploySuccessRate,
            'avg_retries' => $avgRetries,
        ];

        return response()->json([
            'period_days' => $days,
            'tasks_done' => $tasksDone,
            'first_pass_rate' => $firstPassRate,
            'rework_rate' => $reworkRate,
            'avg_retries' => $avgRetries,
            'escaped_defect_rate' => $escapedDefectRate,
            'avg_duration_by_agent' => $avgDurationByAgent,
            'tokens' => [
                'input' => $totals?->input_tokens !== null ? (int) $totals->input_tokens : null,
                'output' => $totals?->output_tokens !== null ? (int) $totals->output_tokens : null,
                'total' => $totals?->total_tokens !== null ? (int) $totals->total_tokens : null,
            ],
            'cost_total' => $costTotal,
            'cost_per_task' => $costPerTask,
            'tokens_per_task' => $tokensPerTask,
            'tokens_by_agent' => $tokensByAgent,
            'cost_by_agent' => $costByAgent,
            'token_usage' => $this->tokenUsage($executionQuery, $since),
            'deploy' => [
                'success_rate' => $deploySuccessRate,
                'total' => $deployTotal,
                'failed' => $deployTotal - $deploySuccess,
                'failures_by_type' => $failuresByType,
            ],
            'lead_time_avg_seconds' => $leadTimeAvg,
            'processing_time_avg_seconds' => $processingTimeAvg,
            'history' => $history,
            'insights' => $this->insights->build($metricsForInsight, $tasksDone),
        ]);
    }

    /**
     * Daily token usage per provider and agent, one value per calendar day (zero-filled)
     * so the charts get a continuous x-axis. `daily_goal` is the user's "ideal" line;
     * `baseline` (mean of the days that had any usage) is what the chart falls back to
     * while no goal is set.
     */
    private function tokenUsage(callable $executionQuery, Carbon $since): array
    {
        $agents = ['developer', 'tester', 'devops'];
        $dates = [];
        for ($day = $since->copy()->startOfDay(); $day->lte(now()); $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        $usage = [];
        $executionQuery()
            ->whereNotNull('agent_executions.total_tokens')
            ->whereNotNull('agent_executions.finished_at')
            ->selectRaw("COALESCE(agent_executions.provider, 'codex') as provider, agent_executions.agent as agent, DATE(agent_executions.finished_at) as day, SUM(agent_executions.total_tokens) as tokens")
            ->groupByRaw("COALESCE(agent_executions.provider, 'codex'), agent_executions.agent, DATE(agent_executions.finished_at)")
            ->get()
            ->each(function ($row) use (&$usage) {
                $usage[$row->provider][$row->agent][(string) $row->day] = (int) $row->tokens;
            });

        $goals = TokenGoal::pluck('daily_tokens', 'provider');

        $providers = [];
        foreach (AgentProviderSetting::PROVIDERS as $provider) {
            $series = [];
            foreach ($agents as $agent) {
                $series[$agent] = array_map(fn (string $date) => $usage[$provider][$agent][$date] ?? 0, $dates);
            }
            $daily = array_map(fn (int $index) => array_sum(array_column($series, $index)), array_keys($dates));
            $activeDays = count(array_filter($daily));

            $providers[] = [
                'id' => $provider,
                'label' => AgentProviderSetting::LABELS[$provider],
                'total' => array_sum($daily),
                'series' => $series,
                'baseline' => $activeDays > 0 ? (int) round(array_sum($daily) / $activeDays) : null,
                'daily_goal' => isset($goals[$provider]) ? (int) $goals[$provider] : null,
            ];
        }

        return ['days' => $dates, 'providers' => $providers];
    }
}
