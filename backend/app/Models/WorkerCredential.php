<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerCredential extends Model
{
    public const PURPOSE_PROVIDER_API_KEY = 'provider_api_key';

    public const PURPOSE_REPOSITORY_PASSWORD = 'repository_password';

    protected $fillable = ['task_id', 'purpose', 'token_hash', 'expires_at', 'used_at'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime'];
}
