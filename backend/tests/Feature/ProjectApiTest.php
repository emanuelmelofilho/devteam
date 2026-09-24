<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
            'source_type' => 'local',
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
            'source_type' => 'local',
            'directory' => '/workspace/meu-projeto',
            'description' => 'Projeto de exemplo',
            'frontend' => 'React',
            'backend' => 'Laravel',
            'database' => 'PostgreSQL',
        ]);

        $response->assertCreated()
            ->assertJsonPath('source_type', 'local')
            ->assertJsonPath('directory', '/workspace/meu-projeto')
            ->assertJsonPath('frontend', 'React')
            ->assertJsonPath('backend', 'Laravel')
            ->assertJsonPath('database', 'PostgreSQL');
        $this->assertDatabaseHas('projects', ['name' => 'Meu projeto', 'directory' => '/workspace/meu-projeto', 'frontend' => 'React']);
        $this->assertDatabaseHas('activity_logs', ['project_id' => $response->json('id'), 'event' => 'project.created']);
    }

    public function test_source_type_is_required_when_creating_a_project(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/kanban/projects', ['name' => 'Sem tipo', 'directory' => '/workspace/x'])
            ->assertUnprocessable()->assertJsonValidationErrors('source_type');
    }

    public function test_directory_is_required_when_creating_a_local_project(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/kanban/projects', ['name' => 'Sem diretório', 'source_type' => 'local'])
            ->assertUnprocessable()->assertJsonValidationErrors('directory');
    }

    public function test_authenticated_user_can_update_a_project(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Antigo', 'source_type' => 'local', 'directory' => '/workspace/antigo']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/kanban/projects/{$project->id}", [
            'name' => 'Novo nome',
            'source_type' => 'local',
            'directory' => '/workspace/novo',
            'description' => 'Descrição atualizada',
        ])->assertOk()->assertJsonPath('name', 'Novo nome')->assertJsonPath('directory', '/workspace/novo');

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Novo nome', 'directory' => '/workspace/novo']);
    }

    public function test_authenticated_user_can_register_a_repository_project_without_a_directory(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/kanban/projects', [
            'name' => 'Projeto remoto',
            'source_type' => 'repository',
            'repository_provider' => 'github',
            'repository_url' => 'https://github.com/acme/app.git',
            'repository_auth_type' => 'none',
        ]);

        $response->assertCreated()
            ->assertJsonPath('source_type', 'repository')
            ->assertJsonPath('directory', null)
            ->assertJsonPath('repository_provider', 'github')
            ->assertJsonPath('has_repository_password', false);
        $this->assertDatabaseHas('projects', ['name' => 'Projeto remoto', 'source_type' => 'repository', 'directory' => null]);
    }

    public function test_repository_provider_and_url_are_required_for_repository_projects(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/kanban/projects', ['name' => 'Incompleto', 'source_type' => 'repository'])
            ->assertUnprocessable()->assertJsonValidationErrors(['repository_provider', 'repository_url']);
    }

    public function test_repository_password_is_encrypted_stored_hidden_and_can_be_updated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $create = $this->postJson('/api/kanban/projects', [
            'name' => 'Projeto privado', 'source_type' => 'repository', 'repository_provider' => 'gitlab',
            'repository_url' => 'https://gitlab.com/acme/app.git', 'repository_auth_type' => 'https',
            'repository_username' => 'bot', 'repository_password' => 'segredo-123',
        ])->assertCreated()->assertJsonMissing(['repository_password' => 'segredo-123'])->assertJsonPath('has_repository_password', true);
        $project = Project::findOrFail($create->json('id'));

        $stored = DB::table('projects')->where('id', $project->id)->value('repository_password');
        $this->assertNotSame('segredo-123', $stored);
        $this->assertSame('segredo-123', $project->fresh()->repository_password);

        // Blank password on update keeps the one already stored.
        $this->patchJson("/api/kanban/projects/{$project->id}", [
            'name' => 'Projeto privado', 'source_type' => 'repository', 'repository_provider' => 'gitlab',
            'repository_url' => 'https://gitlab.com/acme/app.git', 'repository_auth_type' => 'https',
            'repository_username' => 'bot', 'repository_password' => '',
        ])->assertOk()->assertJsonMissing(['repository_password' => '']);
        $this->assertSame('segredo-123', $project->fresh()->repository_password);
    }

    public function test_switching_a_project_to_local_clears_its_repository_credentials(): void
    {
        $user = User::factory()->create();
        $project = Project::create([
            'name' => 'Vai virar local', 'source_type' => 'repository', 'repository_provider' => 'github',
            'repository_url' => 'https://github.com/acme/app.git', 'repository_auth_type' => 'ssh',
            'repository_ssh_key_path' => '~/.ssh/id_rsa_app',
        ]);

        $this->actingAs($user, 'sanctum')->patchJson("/api/kanban/projects/{$project->id}", [
            'name' => 'Vai virar local', 'source_type' => 'local', 'directory' => '/workspace/local',
        ])->assertOk();

        $project->refresh();
        $this->assertSame('local', $project->source_type);
        $this->assertNull($project->repository_provider);
        $this->assertNull($project->repository_url);
        $this->assertNull($project->repository_ssh_key_path);
    }

    public function test_switching_repository_auth_type_from_ssh_to_https_clears_the_key_path(): void
    {
        $user = User::factory()->create();
        $project = Project::create([
            'name' => 'Muda auth', 'source_type' => 'repository', 'repository_provider' => 'github',
            'repository_url' => 'https://github.com/acme/app.git', 'repository_auth_type' => 'ssh',
            'repository_ssh_key_path' => '~/.ssh/id_rsa_app',
        ]);

        $this->actingAs($user, 'sanctum')->patchJson("/api/kanban/projects/{$project->id}", [
            'name' => 'Muda auth', 'source_type' => 'repository', 'repository_provider' => 'github',
            'repository_url' => 'https://github.com/acme/app.git', 'repository_auth_type' => 'https',
            'repository_username' => 'bot', 'repository_password' => 'nova-senha',
        ])->assertOk();

        $project->refresh();
        $this->assertNull($project->repository_ssh_key_path);
        $this->assertSame('nova-senha', $project->repository_password);
    }

    public function test_authenticated_user_can_delete_a_project_and_its_tasks(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Projeto antigo', 'source_type' => 'local', 'directory' => '/workspace/antigo']);
        $task = Task::create(['project_id' => $project->id, 'title' => 'Tarefa antiga', 'lane' => 'todo']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/kanban/projects/{$project->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }
}
