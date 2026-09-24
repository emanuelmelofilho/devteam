<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    public const TYPES = ['feature', 'improvement', 'fix'];

    protected $fillable = ['project_id', 'title', 'description', 'type', 'lane', 'assigned_agent', 'branch', 'work_summary', 'version', 'attempt', 'max_attempts', 'assignee_id', 'reporter_id', 'origin_task_id', 'metadata'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function executions()
    {
        return $this->hasMany(AgentExecution::class);
    }

    public function originTask()
    {
        return $this->belongsTo(Task::class, 'origin_task_id');
    }

    public function escapedBugs()
    {
        return $this->hasMany(Task::class, 'origin_task_id');
    }
}
