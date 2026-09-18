<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ClaudeSetting;
use Illuminate\Http\Request;

class ClaudeSettingsController extends Controller
{
    public function index()
    {
        $setting = ClaudeSetting::latest()->first();

        return response()->json([
            'configured' => (bool) $setting,
            'masked_key' => $setting ? '••••••••••••'.substr($setting->api_key, -4) : null,
            'updated_at' => $setting?->updated_at,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'api_key' => ['required', 'string', 'min:20', 'max:500', 'starts_with:sk-ant-api'],
        ], [
            'api_key.starts_with' => 'Use uma chave da API da Anthropic (sk-ant-api…), criada em console.anthropic.com.',
        ]);

        $setting = ClaudeSetting::latest()->first();
        if ($setting) {
            $setting->update(['api_key' => $data['api_key'], 'updated_by' => $request->user()->id]);
        } else {
            $setting = ClaudeSetting::create(['api_key' => $data['api_key'], 'updated_by' => $request->user()->id]);
        }

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'scope' => 'devteam',
            'event' => 'devteam.updated',
            'title' => 'Chave do Claude atualizada',
            'details' => 'A credencial da API da Anthropic usada pelo Devteam foi atualizada com segurança. O valor da chave não é registrado no histórico.',
        ]);

        return response()->json([
            'message' => 'Chave salva com segurança.',
            'configured' => true,
            'masked_key' => '••••••••••••'.substr($setting->api_key, -4),
            'updated_at' => $setting->updated_at,
        ]);
    }
}
