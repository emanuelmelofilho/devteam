<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ActivityLog;
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
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'directory' => 'required|string|max:2048',
            'description' => 'nullable|string',
            'frontend' => 'nullable|string|max:80',
            'backend' => 'nullable|string|max:80',
            'database' => 'nullable|string|max:80',
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:2048',
        ]);
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
            'details' => "O projeto {$project->name} foi cadastrado no Devteam com o diretório {$project->directory}.",
            'metadata' => ['stack' => array_filter([$project->frontend, $project->backend, $project->database])],
        ]);

        return $project;
    }

    public function show(Project $project)
    {
        return $project->load('tasks');
    }

    public function update(Request $request, Project $project)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'directory' => 'sometimes|required|string|max:2048',
            'description' => 'nullable|string',
            'frontend' => 'nullable|string|max:80',
            'backend' => 'nullable|string|max:80',
            'database' => 'nullable|string|max:80',
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:2048',
            'remove_logo' => 'nullable|boolean',
        ]);
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
