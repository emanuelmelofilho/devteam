<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsappSetting;
use App\Models\ActivityLog;
use App\Notifications\TaskUpdatedNotification;
use App\Services\AgentQueueService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
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
            'assigned_agent' => 'required|in:developer_worker,tester_worker,devops_worker',
            'assignee_id' => 'nullable|exists:users,id',
        ]);
        $initialLanes = ['developer_worker' => 'todo', 'tester_worker' => 'review', 'devops_worker' => 'publishing'];
        $task = Task::create(array_merge($data, [
            'lane' => $initialLanes[$data['assigned_agent']],
            'branch' => $data['assigned_agent'] === 'developer_worker' ? 'developer' : null,
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

        $previousLane = $task->lane;
        $previousAgent = $task->assigned_agent;
        if (isset($data['lane'])) {
            $allowedLanes = ['todo', 'in_progress', 'review', 'publishing', 'published'];
            if (! in_array($data['lane'], $allowedLanes, true)) {
                return response()->json(['message' => 'Raia inválida.'], 422);
            }

            $transitions = [
                'todo' => ['in_progress'],
                'in_progress' => ['review'],
                'review' => ['in_progress', 'publishing'],
                'done' => ['publishing'],
                'publishing' => ['published', 'in_progress'],
                'published' => [],
            ];
            if ($data['lane'] !== $task->lane && ! in_array($data['lane'], $transitions[$task->lane] ?? [], true)) {
                return response()->json(['message' => "Transição de {$task->lane} para {$data['lane']} não permitida."], 422);
            }
            if ($data['lane'] === 'review' && blank($data['work_summary'] ?? $task->work_summary)) {
                return response()->json(['message' => 'Inclua um resumo do trabalho antes da revisão.'], 422);
            }
            if ($data['lane'] === 'published' && blank($data['version'] ?? $task->version)) {
                return response()->json(['message' => 'Informe a versão no formato 1.2.3 antes de publicar.'], 422);
            }

            $agentByLane = ['in_progress' => 'developer_worker', 'review' => 'tester_worker', 'publishing' => 'devops_worker', 'published' => 'devops_worker'];
            if (isset($agentByLane[$data['lane']])) {
                $data['assigned_agent'] = $agentByLane[$data['lane']];
            }
            if ($data['lane'] === 'in_progress') {
                $data['branch'] = 'developer';
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

        if ($previousLane !== $task->lane) {
            $labels = ['todo' => 'A fazer', 'in_progress' => 'Em andamento', 'review' => 'Em revisão', 'publishing' => 'Em publicação', 'published' => 'Publicado'];
            ActivityLog::create([
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'user_id' => $request->user()->id,
                'event' => $lifecycleEvent ? "task.{$lifecycleEvent}" : 'task.moved',
                'title' => $lifecycleEvent === 'started' ? 'Atividade iniciada' : ($lifecycleEvent === 'finished' ? 'Atividade finalizada' : 'Etapa atualizada'),
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

        return $task;
    }

    public function show(Task $task)
    {
        return $task->load('comments');
    }

    public function retry(Request $request, Task $task)
    {
        $agentByLane = [
            'todo' => 'developer_worker',
            'in_progress' => 'developer_worker',
            'review' => 'tester_worker',
            'publishing' => 'devops_worker',
        ];
        $agent = $agentByLane[$task->lane] ?? null;
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
