<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProjectController extends Controller
{
    public function index()
    {
        return Project::with('tasks')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::validationRules());
        $data = $this->normalizeSourceFields($data);
        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('project-logos', 'project_logos');
        }
        unset($data['logo']);
        $project = Project::create($data);
        ActivityLog::create([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'event' => 'project.created',
            'title' => 'Projeto cadastrado',
            'details' => 'O projeto '.$project->name.' foi cadastrado no Devteam com '.$this->sourceDescription($project).'.',
            'metadata' => ['stack' => array_filter([$project->frontend, $project->backend, $project->database]), 'source_type' => $project->source_type, 'repository_provider' => $project->repository_provider],
        ]);

        return $project;
    }

    /** Shared by store()/update(): validation rules for a project, `directory` and the repository
     * fields required_if'd on `source_type` so only the fields that apply to the chosen source are
     * enforced. */
    private static function validationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'source_type' => 'required|in:local,repository',
            'directory' => 'required_if:source_type,local|nullable|string|max:2048',
            'description' => 'nullable|string',
            'frontend' => 'nullable|string|max:80',
            'backend' => 'nullable|string|max:80',
            'database' => 'nullable|string|max:80',
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:2048',
            'repository_provider' => 'required_if:source_type,repository|nullable|in:github,gitlab,bitbucket,other',
            'repository_url' => 'required_if:source_type,repository|nullable|string|max:2048',
            'repository_auth_type' => 'nullable|in:none,https,ssh',
            'repository_username' => 'nullable|string|max:255',
            'repository_password' => 'nullable|string',
            'repository_ssh_key_path' => 'nullable|string|max:1024',
        ];
    }

    /**
     * Clears whichever fields don't apply to the chosen source/auth type, so switching from
     * repository back to local (or between auth types) never leaves orphaned credentials behind.
     * On update(), a blank repository_password is left out of $data entirely (rather than nulled)
     * so update() doesn't touch the column and the stored password survives.
     */
    private function normalizeSourceFields(array $data, ?Project $project = null): array
    {
        if ($data['source_type'] === 'local') {
            $data['repository_provider'] = $data['repository_url'] = $data['repository_auth_type'] = null;
            $data['repository_username'] = $data['repository_password'] = $data['repository_ssh_key_path'] = null;

            return $data;
        }

        $data['directory'] = null;
        $authType = $data['repository_auth_type'] ?? $project?->repository_auth_type;
        if ($authType === 'https') {
            if ($project && blank($data['repository_password'] ?? null)) {
                unset($data['repository_password']);
            }
            $data['repository_ssh_key_path'] = null;
        } elseif ($authType === 'ssh') {
            $data['repository_username'] = $data['repository_password'] = null;
        } else {
            $data['repository_username'] = $data['repository_password'] = $data['repository_ssh_key_path'] = null;
        }

        return $data;
    }

    private function sourceDescription(Project $project): string
    {
        return $project->source_type === 'repository'
            ? "o repositório {$project->repository_url} ({$project->repository_provider})"
            : "o diretório {$project->directory}";
    }

    public function show(Project $project)
    {
        return $project->load('tasks');
    }

    public function update(Request $request, Project $project)
    {
        $data = $request->validate(self::validationRules() + ['remove_logo' => 'nullable|boolean']);
        $data = $this->normalizeSourceFields($data, $project);
        if (($data['remove_logo'] ?? false) && $project->logo_path) {
            Storage::disk('project_logos')->delete($project->logo_path);
            $data['logo_path'] = null;
        }
        if ($request->hasFile('logo')) {
            if ($project->logo_path) {
                Storage::disk('project_logos')->delete($project->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('project-logos', 'project_logos');
        }
        unset($data['logo'], $data['remove_logo']);
        $project->update($data);
        ActivityLog::create([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'event' => 'project.updated',
            'title' => 'Projeto atualizado',
            'details' => "As informações e tecnologias do projeto {$project->name} foram atualizadas.",
        ]);

        return $project;
    }

    public function destroy(Project $project)
    {
        ActivityLog::create([
            'user_id' => request()->user()->id,
            'event' => 'project.deleted',
            'title' => 'Projeto excluído',
            'details' => "O projeto {$project->name} e suas tarefas vinculadas foram removidos do Devteam.",
        ]);
        if ($project->logo_path) {
            Storage::disk('project_logos')->delete($project->logo_path);
        }
        $project->delete();

        return response()->noContent();
    }
}
