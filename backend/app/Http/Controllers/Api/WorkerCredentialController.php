<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentProviderSetting;
use App\Models\Task;
use App\Models\WorkerCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkerCredentialController extends Controller
{
    public function exchange(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string|size:80',
            'task_id' => 'required|integer',
            'purpose' => ['sometimes', Rule::in([WorkerCredential::PURPOSE_PROVIDER_API_KEY, WorkerCredential::PURPOSE_REPOSITORY_PASSWORD])],
            'provider' => ['sometimes', Rule::in(AgentProviderSetting::PROVIDERS)],
        ]);
        $purpose = $data['purpose'] ?? WorkerCredential::PURPOSE_PROVIDER_API_KEY;
        $provider = $data['provider'] ?? AgentProviderSetting::CODEX;

        $credential = DB::transaction(function () use ($data, $purpose) {
            $item = WorkerCredential::query()
                ->where('token_hash', hash('sha256', $data['token']))
                ->where('task_id', $data['task_id'])
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            if (! $item || $item->used_at || $item->expires_at->isPast()) {
                return null;
            }

            $item->update(['used_at' => now()]);

            return $item;
        });

        if (! $credential) {
            return response()->json(['message' => 'Credencial temporária inválida ou expirada.'], 410);
        }

        if ($purpose === WorkerCredential::PURPOSE_REPOSITORY_PASSWORD) {
            $project = Task::with('project')->find($data['task_id'])?->project;
            if (! $project || ! filled($project->repository_password)) {
                return response()->json(['message' => 'Credencial de repositório não configurada.'], 422);
            }

            return response()->json(['repository_username' => $project->repository_username, 'repository_password' => $project->repository_password]);
        }

        $apiKey = AgentProviderSetting::apiKeyFor($provider);
        if (! $apiKey) {
            $label = $provider === AgentProviderSetting::CLAUDE ? 'Claude' : 'Codex';

            return response()->json(['message' => "Chave do {$label} não configurada."], 422);
        }

        return response()->json(['api_key' => $apiKey]);
    }
}
