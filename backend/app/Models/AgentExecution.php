<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentExecution extends Model
{
    protected $fillable = [
        'task_id', 'agent', 'provider', 'attempt', 'status', 'summary', 'solution',
        'started_at', 'finished_at', 'duration_ms',
        'input_tokens', 'cached_input_tokens', 'output_tokens', 'total_tokens', 'estimated_cost',
        'model', 'prompt_version', 'branch', 'commit_hash', 'commit_message', 'files', 'patch',
        'error_type', 'error_message', 'next_suggested', 'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metadata' => 'array',
        'files' => 'array',
        'estimated_cost' => 'decimal:6',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }
}
