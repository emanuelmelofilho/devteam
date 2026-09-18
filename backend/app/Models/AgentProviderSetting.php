<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentProviderSetting extends Model
{
    public const CODEX = 'codex';

    public const CLAUDE = 'claude';

    public const PROVIDERS = [self::CODEX, self::CLAUDE];

    public const LABELS = [
        self::CODEX => 'Codex (OpenAI)',
        self::CLAUDE => 'Claude (Anthropic)',
    ];

    protected $fillable = ['provider', 'updated_by'];

    /** Codex stays the default so installs that never chose a provider keep working. */
    public static function current(): string
    {
        return static::latest('id')->value('provider') ?? self::CODEX;
    }

    public static function apiKeyFor(string $provider): ?string
    {
        $model = $provider === self::CLAUDE ? ClaudeSetting::class : CodexSetting::class;

        return $model::latest()->first()?->api_key;
    }
}
