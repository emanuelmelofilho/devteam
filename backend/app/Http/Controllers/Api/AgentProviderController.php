<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AgentProviderSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentProviderController extends Controller
{
    public function index()
    {
        return response()->json($this->state());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(AgentProviderSetting::PROVIDERS)],
        ]);

        AgentProviderSetting::create(['provider' => $data['provider'], 'updated_by' => $request->user()->id]);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'scope' => 'devteam',
            'event' => 'devteam.updated',
            'title' => 'Provedor dos agentes alterado',
            'details' => 'Developer, Tester e DevOps passaram a executar com '.AgentProviderSetting::LABELS[$data['provider']].'.',
        ]);

        return response()->json(['message' => 'Provedor alterado.'] + $this->state());
    }

    private function state(): array
    {
        return [
            'provider' => AgentProviderSetting::current(),
            'providers' => collect(AgentProviderSetting::PROVIDERS)->map(fn (string $provider) => [
                'id' => $provider,
                'label' => AgentProviderSetting::LABELS[$provider],
                'configured' => (bool) AgentProviderSetting::apiKeyFor($provider),
            ])->values(),
        ];
    }
}
