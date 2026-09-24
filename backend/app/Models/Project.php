<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'directory', 'description', 'frontend', 'backend', 'database', 'logo_path',
        'source_type', 'repository_provider', 'repository_url', 'repository_auth_type',
        'repository_username', 'repository_password', 'repository_ssh_key_path',
    ];

    protected $hidden = ['repository_password'];

    protected $casts = ['repository_password' => 'encrypted'];

    protected $appends = ['logo_url', 'has_repository_password'];

    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        $url = Storage::disk('project_logos')->url($this->logo_path);

        return parse_url($url, PHP_URL_PATH) ?: $url;
    }

    public function getHasRepositoryPasswordAttribute(): bool
    {
        return filled($this->repository_password);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }
}
