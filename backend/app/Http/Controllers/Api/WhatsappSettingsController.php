<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappSetting;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

class WhatsappSettingsController extends Controller
{
    public function index()
    {
        $setting = WhatsappSetting::latest()->first();

        return $setting ? array_merge($setting->toArray(), [
            'has_token' => filled($setting->token),
            'has_client_token' => filled($setting->client_token),
        ]) : null;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'api_url' => 'required|url',
            'token' => 'nullable|string',
            'phone' => 'required|string|max:30',
            'client_token' => 'nullable|string',
        ]);
        $previous = WhatsappSetting::latest()->first();
        if (blank($data['token'] ?? null) && $previous) {
            $data['token'] = $previous->token;
        }
        if (blank($data['client_token'] ?? null) && $previous) {
            $data['client_token'] = $previous->client_token;
        }
        $setting = WhatsappSetting::create($data);

        return $setting;
    }

    public function test(Request $request)
    {
        $data = $request->validate([
            'phone' => 'required|string',
            'message' => 'required|string',
        ]);

        $setting = WhatsappSetting::latest()->first();
        if (! $setting || ! $setting->api_url) {
            return response()->json(['message' => 'WhatsApp not configured'], 422);
        }

        $service = new WhatsAppService($setting->api_url, $setting->client_token);
        if (! $service->sendMessage($data['phone'], $data['message'])) {
            return response()->json(['message' => 'Falha no envio'], 502);
        }

        return response()->json(['message' => 'Sent (best-effort)']);
    }
}
