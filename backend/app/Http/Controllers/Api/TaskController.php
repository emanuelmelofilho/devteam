<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AgentExecution;
use App\Models\AiModelPrice;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsappSetting;
use App\Notifications\TaskUpdatedNotification;
use App\Services\AgentQueueService;
use App\Services\OrchestratorService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    private const AGENT_LABELS = ['developer' => 'Desenvolvedor', 'tester' => 'Testador', 'devops' => 'DevOps'];

    public function __construct(private readonly OrchestratorService $orchestrator) {}

    public function index(Request $request)
    {
        $project = $request->get('project');
        $query = Task::query();
        if ($project) {
            $query->where('project_id', $project);
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'title' => 'required|string',
            'description' => 'required|string',
            'type' => 'sometimes|in:'.implode(',', Task::TYPES),
            'assigned_agent' => 'required|in:developer_worker,tester_worker,devops_worker',
            'assignee_id' => 'nullable|exists:users,id',
            'origin_task_id' => 'nullable|exists:tasks,id',
        ]);
        $initialLanes = ['developer_worker' => 'todo', 'tester_worker' => 'review', 'devops_worker' => 'publishing'];
        $task = Task::create(array_merge($data, [
            'lane' => $initialLanes[$data['assigned_agent']],
        ]));
        ActivityLog::create([
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'event' => 'task.created',
            'title' => 'Atividade criada',
            'details' => "A atividade {$task->title} foi criada e está aguardando o início do trabalho.",
        ]);
        app(AgentQueueService::class)->dispatch($task, $request->bearerToken());

        // Refresh in place (not fresh(), which would return a new instance
        // and lose wasRecentlyCreated / the 201 status) so DB-default
        // columns like attempt/max_attempts are present in the response.
        $task->refresh();

        return $task;
    }

    public function update(Request $request, Task $task)
    {
        $data = $request->validate([
            'title' => 'sometimes|string',
            'description' => 'sometimes|string',
            'lane' => 'sometimes|string',
            'assigned_agent' => 'sometimes|in:developer_worker,tester_worker,devops_worker',
            'work_summary' => 'nullable|string|max:5000',
            'version' => ['nullable', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
            'assignee_id' => 'nullable|exists:users,id',
            'branch' => 'nullable|string|max:255',
            'commit_hash' => ['nullable', 'string', 'regex:/^[a-f0-9]{7,40}$/i'],
        ]);

        $commitHash = $data['commit_hash'] ?? null;
        unset($data['commit_hash']);

        if (isset($data['lane'])) {
            if (! in_array($data['lane'], $this->orchestrator->lanes(), true)) {
                return response()->json(['message' => 'Raia inválida.'], 422);
            }
            if ($data['lane'] !== $task->lane && ! $this->orchestrator->canTransition($task->lane, $data['lane'])) {
                return response()->json(['message' => "Transição de {$task->lane} para {$data['lane']} não permitida."], 422);
            }
            if ($data['lane'] === 'review' && blank($data['work_summary'] ?? $task->work_summary)) {
                return response()->json(['message' => 'Inclua um resumo do trabalho antes da revisão.'], 422);
            }
            if ($data['lane'] === 'published' && blank($data['version'] ?? $task->version)) {
                return response()->json(['message' => 'Informe a versão no formato 1.2.3 antes de publicar.'], 422);
            }
        }

        $this->applyLaneChange($request, $task, $data, $commitHash);

        return $task;
    }

    /**
     * Called by workers to report an outcome. The worker never decides the
     * next lane — this delegates to OrchestratorService, which is the only
     * authority on the state machine (agents/orchestrator/state_machine.php).
     */
    public function report(Request $request, Task $task)
    {
        $data = $request->validate([
            'execution_id' => 'nullable|integer|exists:agent_executions,id',
            'outcome' => 'required|in:success,bug,blocked,publish_blocked',
            'summary' => 'required|string|max:5000',
            'branch' => 'nullable|string|max:255',
            'commit_hash' => ['nullable', 'string', 'regex:/^[a-f0-9]{7,40}$/i'],
            'version' => ['nullable', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
            'tests' => 'nullable|array',
            'tests.executed' => 'nullable|boolean',
            'tests.passed' => 'nullable|integer',
            'tests.failed' => 'nullable|integer',
            'next' => 'nullable|string|max:30',
            'error_type' => 'nullable|string|max:100',
            'error_message' => 'nullable|string',
            'usage' => 'nullable|array',
            'usage.input_tokens' => 'nullable|integer',
            'usage.cached_input_tokens' => 'nullable|integer',
            'usage.output_tokens' => 'nullable|integer',
            'usage.reasoning_output_tokens' => 'nullable|integer',
            'cost_usd' => 'nullable|numeric|min:0|max:10000',
            'solution' => 'nullable|string|max:8000',
            'commit_message' => 'nullable|string|max:5000',
            'files' => 'nullable|array|max:300',
            'files.*.path' => 'required|string|max:500',
            'files.*.additions' => 'nullable|integer|min:0',
            'files.*.deletions' => 'nullable|integer|min:0',
            'patch' => 'nullable|string|max:120000',
            'model' => 'nullable|string|max:100',
            'prompt_version' => 'nullable|string|max:20',
        ]);

        $execution = ! empty($data['execution_id']) ? $this->finalizeExecution($data['execution_id'], $data) : null;

        $decision = $this->orchestrator->decideAfterOutcome($task, $data['outcome']);

        if ($decision['lane'] === 'published' && blank($data['version'] ?? $task->version)) {
            return response()->json(['message' => 'DevOps não informou a versão publicada.'], 422);
        }

        $fields = ['lane' => $decision['lane'], 'attempt' => $decision['attempt'], 'work_summary' => $data['summary']];
        if (! empty($data['branch'])) {
            $fields['branch'] = $data['branch'];
        }
        if (! empty($data['version'])) {
            $fields['version'] = $data['version'];
        }

        $this->applyLaneChange($request, $task, $fields, $data['commit_hash'] ?? null, $execution);

        return response()->json(['task' => $task->fresh(), 'blocked' => $decision['blocked']]);
    }

    private function finalizeExecution(int $executionId, array $data): ?AgentExecution
    {
        $execution = AgentExecution::find($executionId);
        if (! $execution) {
            return null;
        }

        $usage = $data['usage'] ?? [];
        $inputTokens = $usage['input_tokens'] ?? null;
        $outputTokens = $usage['output_tokens'] ?? null;
        $totalTokens = $inputTokens !== null && $outputTokens !== null ? $inputTokens + $outputTokens : null;
        $model = $data['model'] ?? null;

        $execution->update([
            'status' => $data['outcome'],
            'finished_at' => now(),
            'duration_ms' => (int) round(abs($execution->started_at->diffInMilliseconds(now()))),
            'input_tokens' => $inputTokens,
            'cached_input_tokens' => $usage['cached_input_tokens'] ?? null,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'estimated_cost' => isset($data['cost_usd'])
                ? round((float) $data['cost_usd'], 6)
                : $this->estimateCost($model, $inputTokens, $usage['cached_input_tokens'] ?? null, $outputTokens),
            'model' => $model,
            'prompt_version' => $data['prompt_version'] ?? null,
            'branch' => $data['branch'] ?? $execution->branch,
            'commit_hash' => $data['commit_hash'] ?? null,
            'error_type' => $data['error_type'] ?? null,
            'error_message' => $data['error_message'] ?? null,
            'next_suggested' => $data['next'] ?? null,
            'metadata' => $data['tests'] ?? null,
            'summary' => $data['summary'],
            'solution' => $data['solution'] ?? null,
            'commit_message' => $data['commit_message'] ?? null,
            'files' => $data['files'] ?? null,
            'patch' => $data['patch'] ?? null,
        ]);

        return $execution;
    }

    /**
     * Estimate from the price table when the worker did not report the provider's own cost.
     * The model name is matched as a prefix (dated snapshots like `claude-haiku-4-5-20251001`
     * use the `claude-haiku-4-5` row, the longest match wins) and cached input tokens are
     * billed at the cached rate, which is far below the input rate.
     */
    private function estimateCost(?string $model, ?int $inputTokens, ?int $cachedTokens, ?int $outputTokens): ?float
    {
        if (! $model || $inputTokens === null || $outputTokens === null) {
            return null;
        }

        $today = now()->toDateString();
        $price = AiModelPrice::query()
            ->where('valid_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->get()
            ->filter(fn (AiModelPrice $candidate) => str_starts_with($model, $candidate->model))
            ->sort(fn (AiModelPrice $a, AiModelPrice $b) => [strlen($b->model), $b->valid_from] <=> [strlen($a->model), $a->valid_from])
            ->first();
        if (! $price) {
            return null;
        }

        $cached = min(max($cachedTokens ?? 0, 0), $inputTokens);
        $cachedRate = (float) ($price->cached_input_price_per_million ?? $price->input_price_per_million);

        $cost = (($inputTokens - $cached) / 1_000_000) * (float) $price->input_price_per_million
            + ($cached / 1_000_000) * $cachedRate
            + ($outputTokens / 1_000_000) * (float) $price->output_price_per_million;

        return round($cost, 6);
    }

    /**
     * Shared by update() and report(): applies the field changes (lane
     * included), logs the activity, redispatches to the next queue and
     * sends notifications — the same side effects regardless of whether a
     * human dragged the card or a worker reported an outcome.
     */
    private function applyLaneChange(Request $request, Task $task, array $data, ?string $commitHash = null, ?AgentExecution $execution = null): void
    {
        $previousLane = $task->lane;
        $previousAgent = $task->assigned_agent;

        if (isset($data['lane'])) {
            $agent = $this->orchestrator->agentForLane($data['lane']);
            if ($agent) {
                $data['assigned_agent'] = $agent;
            }
            if ($data['lane'] === 'in_progress' && blank($task->branch) && blank($data['branch'] ?? null)) {
                $data['branch'] = $this->orchestrator->branchFor($task);
            }
        }

        $task->update($data);
        if ($commitHash) {
            $task->update(['metadata' => array_merge($task->metadata ?? [], ['commit_hash' => $commitHash])]);
        }

        $lifecycleEvent = match (true) {
            $previousLane !== 'in_progress' && $task->lane === 'in_progress' => 'started',
            $previousLane !== 'published' && $task->lane === 'published' => 'finished',
            default => null,
        };

        $labels = ['todo' => 'A fazer', 'in_progress' => 'Em andamento', 'review' => 'Em revisão', 'publishing' => 'Em publicação', 'published' => 'Publicado', 'blocked' => 'Bloqueado'];
        if ($previousLane === $task->lane && $execution) {
            // The agent ran but the task stayed where it was (e.g. a blocked publication): the step
            // still belongs in the history, with everything it produced.
            $agentLabel = self::AGENT_LABELS[$execution->agent] ?? $execution->agent;
            ActivityLog::create([
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'user_id' => $request->user()->id,
                'agent_execution_id' => $execution->id,
                'event' => 'task.step',
                'title' => "Etapa executada: {$agentLabel}",
                'details' => "{$agentLabel} terminou com o resultado \"{$execution->status}\" e a atividade {$task->title} permaneceu em ".($labels[$task->lane] ?? $task->lane).'.'.($task->work_summary ? " Resumo: {$task->work_summary}" : ''),
                'metadata' => ['from' => $previousLane, 'to' => $task->lane, 'version' => $task->version, 'branch' => $task->branch, 'commit_hash' => $commitHash ?? ($task->metadata['commit_hash'] ?? null)],
            ]);
        }

        if ($previousLane !== $task->lane) {
            ActivityLog::create([
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'user_id' => $request->user()->id,
                'agent_execution_id' => $execution?->id,
                'event' => $lifecycleEvent ? "task.{$lifecycleEvent}" : ($task->lane === 'blocked' ? 'task.blocked' : 'task.moved'),
                'title' => $lifecycleEvent === 'started' ? 'Atividade iniciada' : ($lifecycleEvent === 'finished' ? 'Atividade finalizada' : ($task->lane === 'blocked' ? 'Atividade bloqueada' : 'Etapa atualizada')),
                'details' => "A atividade {$task->title} passou de ".($labels[$previousLane] ?? $previousLane).' para '.($labels[$task->lane] ?? $task->lane).'.'.($task->work_summary ? " Resumo: {$task->work_summary}" : '').($task->version ? " Versão: {$task->version}." : ''),
                'metadata' => ['from' => $previousLane, 'to' => $task->lane, 'version' => $task->version, 'branch' => $task->branch, 'commit_hash' => $commitHash ?? ($task->metadata['commit_hash'] ?? null)],
            ]);
        }

        $shouldDispatch = ($previousLane !== 'in_progress' && $task->lane === 'in_progress')
            || $task->lane === 'review' && $previousLane !== 'review'
            || $task->lane === 'publishing' && $previousLane !== 'publishing'
            || $previousAgent !== $task->assigned_agent;
        if ($shouldDispatch) {
            app(AgentQueueService::class)->dispatch($task, $request->bearerToken());
        }

        if ($lifecycleEvent) {
            $recipients = User::query()
                ->where('id', $request->user()->id)
                ->when($task->assignee_id, fn ($query) => $query->orWhere('id', $task->assignee_id))
                ->get();
            foreach ($recipients as $recipient) {
                $recipient->notify(new TaskUpdatedNotification($task, $lifecycleEvent));
            }
            // send whatsapp too if configured
            $setting = WhatsappSetting::latest()->first();
            if ($setting && $setting->api_url && $setting->phone) {
                $service = new WhatsAppService($setting->api_url, $setting->client_token);
                $message = $lifecycleEvent === 'started'
                    ? "Devteam: a atividade {$task->title} foi iniciada."
                    : "Devteam: a atividade {$task->title} foi finalizada.";
                if ($task->work_summary) {
                    $message .= " Resumo: {$task->work_summary}";
                }
                if ($task->version) {
                    $message .= " Versão: {$task->version}";
                }
                $service->sendMessage($setting->phone, $message);
            }
        }
    }

    public function show(Task $task)
    {
        return $task->load('comments');
    }

    public function retry(Request $request, Task $task)
    {
        if ($task->lane === 'blocked') {
            // A human intervened, so restart the retry counter and move the
            // task back to development through the same shared transition
            // path used by update()/report() (activity log, dispatch...).
            $this->applyLaneChange($request, $task, ['lane' => 'in_progress', 'attempt' => 1]);

            return response()->json(['message' => 'Tarefa reenfileirada com sucesso.', 'task' => $task->fresh()], 202);
        }

        $agent = $this->orchestrator->retryAgentForLane($task->lane);
        if (! $agent) {
            return response()->json(['message' => 'Esta tarefa não pode ser reenfileirada na situação atual.'], 422);
        }

        $task->update(['assigned_agent' => $agent]);
        $queued = app(AgentQueueService::class)->dispatch($task, $request->bearerToken());
        if (! $queued) {
            return response()->json(['message' => 'Não foi possível publicar a tarefa na fila.'], 503);
        }

        return response()->json([
            'message' => 'Tarefa reenfileirada com sucesso.',
            'task' => $task->fresh(),
        ], 202);
    }

    public function destroy(Task $task)
    {
        if ($task->lane !== 'todo') {
            return response()->json([
                'message' => 'A tarefa somente pode ser excluída enquanto estiver em A fazer.',
            ], 422);
        }

        $task->delete();

        return response()->noContent();
    }
}
