<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $fillable = ['project_id','title','description','lane','assigned_agent','branch','work_summary','version','assignee_id','reporter_id','metadata'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee()
    {
        return $this->belongsTo(\App\Models\User::class, 'assignee_id');
    }

    public function reporter()
    {
        return $this->belongsTo(\App\Models\User::class, 'reporter_id');
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }
}
