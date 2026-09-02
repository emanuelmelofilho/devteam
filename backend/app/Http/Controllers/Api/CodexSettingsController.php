<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CodexSetting;
use Illuminate\Http\Request;

class CodexSettingsController extends Controller
{
    public function index()
    {
        $setting = CodexSetting::latest()->first();

        return response()->json([
            'configured' => (bool) $setting,
            'masked_key' => $setting ? '••••••••••••'.substr($setting->api_key, -4) : null,
            'updated_at' => $setting?->updated_at,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'api_key' => ['required', 'string', 'min:20', 'max:500', 'starts_with:sk-'],
        ]);

        $setting = CodexSetting::latest()->first();
        if ($setting) {
            $setting->update(['api_key' => $data['api_key'], 'updated_by' => $request->user()->id]);
        } else {
            $setting = CodexSetting::create(['api_key' => $data['api_key'], 'updated_by' => $request->user()->id]);
        }

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'scope' => 'devteam',
            'event' => 'devteam.updated',
            'title' => 'Chave do Codex atualizada',
            'details' => 'A credencial da API da OpenAI usada pelo Devteam foi atualizada com segurança. O valor da chave não é registrado no histórico.',
        ]);

        return response()->json([
            'message' => 'Chave salva com segurança.',
            'configured' => true,
            'masked_key' => '••••••••••••'.substr($setting->api_key, -4),
            'updated_at' => $setting->updated_at,
        ]);
    }
}
