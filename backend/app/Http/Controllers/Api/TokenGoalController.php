<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentProviderSetting;
use App\Models\TokenGoal;
use Illuminate\Http\Request;

class TokenGoalController extends Controller
{
    /** Sets the daily token goal (the "ideal" line on the metrics charts); null clears it. */
    public function update(Request $request, string $provider)
    {
        abort_unless(in_array($provider, AgentProviderSetting::PROVIDERS, true), 404);

        $data = $request->validate([
            'daily_tokens' => ['present', 'nullable', 'integer', 'min:1', 'max:10000000000'],
        ]);

        if ($data['daily_tokens'] === null) {
            TokenGoal::where('provider', $provider)->delete();
        } else {
            TokenGoal::updateOrCreate(
                ['provider' => $provider],
                ['daily_tokens' => $data['daily_tokens'], 'updated_by' => $request->user()->id],
            );
        }

        return response()->json(['provider' => $provider, 'daily_goal' => $data['daily_tokens']]);
    }
}
