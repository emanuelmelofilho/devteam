<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = ['project_id', 'task_id', 'user_id', 'agent_execution_id', 'scope', 'event', 'title', 'details', 'metadata'];

    protected $casts = ['metadata' => 'array'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function execution()
    {
        return $this->belongsTo(AgentExecution::class, 'agent_execution_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
