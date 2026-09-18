<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentProviderSetting;
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
            'provider' => ['sometimes', Rule::in(AgentProviderSetting::PROVIDERS)],
        ]);
        $provider = $data['provider'] ?? AgentProviderSetting::CODEX;

        $credential = DB::transaction(function () use ($data) {
            $item = WorkerCredential::query()
                ->where('token_hash', hash('sha256', $data['token']))
                ->where('task_id', $data['task_id'])
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

        $apiKey = AgentProviderSetting::apiKeyFor($provider);
        if (! $apiKey) {
            $label = $provider === AgentProviderSetting::CLAUDE ? 'Claude' : 'Codex';

            return response()->json(['message' => "Chave do {$label} não configurada."], 422);
        }

        return response()->json(['api_key' => $apiKey]);
    }
}
