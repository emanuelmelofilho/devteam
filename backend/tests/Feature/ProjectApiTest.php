<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_upload_a_project_logo(): void
    {
        Storage::fake('project_logos');
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->post('/api/kanban/projects', [
            'name' => 'Projeto com logo',
            'directory' => '/workspace/logo',
            'logo' => UploadedFile::fake()->createWithContent(
                'logo.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
            ),
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Projeto com logo')
            ->assertJsonPath('logo_url', fn ($url) => is_string($url)
                && str_starts_with($url, '/')
                && ! str_starts_with($url, '//'));
        $project = Project::findOrFail($response->json('id'));
        $this->assertNotNull($project->logo_path);
        Storage::disk('project_logos')->assertExists($project->logo_path);
    }

    public function test_authenticated_user_can_create_a_project_with_directory(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/kanban/projects', [
            'name' => 'Meu projeto',
            'directory' => '/workspace/meu-projeto',
            'description' => 'Projeto de exemplo',
            'frontend' => 'React',
            'backend' => 'Laravel',
            'database' => 'PostgreSQL',
        ]);

        $response->assertCreated()
            ->assertJsonPath('directory', '/workspace/meu-projeto')
            ->assertJsonPath('frontend', 'React')
            ->assertJsonPath('backend', 'Laravel')
            ->assertJsonPath('database', 'PostgreSQL');
        $this->assertDatabaseHas('projects', ['name' => 'Meu projeto', 'directory' => '/workspace/meu-projeto', 'frontend' => 'React']);
        $this->assertDatabaseHas('activity_logs', ['project_id' => $response->json('id'), 'event' => 'project.created']);
    }

    public function test_directory_is_required_when_creating_a_project(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/kanban/projects', ['name' => 'Sem diretório'])
            ->assertUnprocessable()->assertJsonValidationErrors('directory');
    }

    public function test_authenticated_user_can_update_a_project(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Antigo', 'directory' => '/workspace/antigo']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/kanban/projects/{$project->id}", [
            'name' => 'Novo nome',
            'directory' => '/workspace/novo',
            'description' => 'Descrição atualizada',
        ])->assertOk()->assertJsonPath('name', 'Novo nome')->assertJsonPath('directory', '/workspace/novo');

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Novo nome', 'directory' => '/workspace/novo']);
    }

    public function test_authenticated_user_can_delete_a_project_and_its_tasks(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Projeto antigo', 'directory' => '/workspace/antigo']);
        $task = Task::create(['project_id' => $project->id, 'title' => 'Tarefa antiga', 'lane' => 'todo']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/kanban/projects/{$project->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }
}
