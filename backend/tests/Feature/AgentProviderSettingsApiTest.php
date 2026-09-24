<?php

namespace Tests\Feature;

use App\Models\AgentProviderSetting;
use App\Models\ClaudeSetting;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkerCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgentProviderSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_claude_key_is_encrypted_and_never_returned_in_full(): void
    {
        $user = User::factory()->create();
        $key = 'sk-ant-api03-secret-value-for-testing';

        $this->actingAs($user, 'sanctum')->postJson('/api/kanban/claude-settings', ['api_key' => $key])
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonMissing(['api_key' => $key]);

        $this->assertNotSame($key, DB::table('claude_settings')->value('api_key'));
        $this->assertSame($key, ClaudeSetting::latest()->first()->api_key);

        $this->getJson('/api/kanban/claude-settings')
            ->assertOk()
            ->assertJsonMissing(['api_key' => $key])
            ->assertJsonPath('masked_key', '••••••••••••ting');
    }

    public function test_claude_key_must_be_an_anthropic_api_key(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/kanban/claude-settings', ['api_key' => 'sk-project-not-an-anthropic-key'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('api_key');
        $this->postJson('/api/kanban/claude-settings', ['api_key' => 'sk-ant-oat01-subscription-token-value'])
            ->assertUnprocessable();
    }

    public function test_provider_defaults_to_codex_and_can_be_switched(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/kanban/agent-provider')
            ->assertOk()
            ->assertJsonPath('provider', 'codex')
            ->assertJsonPath('providers.0.configured', false)
            ->assertJsonPath('providers.1.configured', false);

        $this->postJson('/api/kanban/claude-settings', ['api_key' => 'sk-ant-api03-secret-value-for-testing']);
        $this->postJson('/api/kanban/agent-provider', ['provider' => 'claude'])
            ->assertOk()
            ->assertJsonPath('provider', 'claude')
            ->assertJsonPath('providers.1.configured', true);
        $this->assertSame('claude', AgentProviderSetting::current());

        $this->postJson('/api/kanban/agent-provider', ['provider' => 'codex'])->assertOk();
        $this->assertSame('codex', AgentProviderSetting::current());
    }

    public function test_unknown_provider_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/kanban/agent-provider', ['provider' => 'gemini'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider');
    }

    public function test_worker_receives_the_key_of_the_requested_provider(): void
    {
        $user = User::factory()->create();
        $codexKey = 'sk-project-codex-secret-for-testing';
        $claudeKey = 'sk-ant-api03-claude-secret-for-testing';
        $this->actingAs($user, 'sanctum');
        $this->postJson('/api/kanban/codex-settings', ['api_key' => $codexKey]);
        $this->postJson('/api/kanban/claude-settings', ['api_key' => $claudeKey]);
        $task = $this->task();

        $this->postJson('/api/kanban/worker/credential', $this->exchange($task, 'claude'))
            ->assertOk()->assertJsonPath('api_key', $claudeKey);
        $this->postJson('/api/kanban/worker/credential', $this->exchange($task, 'codex'))
            ->assertOk()->assertJsonPath('api_key', $codexKey);
        // Workers installed before the provider option omit it and expect the Codex key.
        $this->postJson('/api/kanban/worker/codex-credential', $this->exchange($task))
            ->assertOk()->assertJsonPath('api_key', $codexKey);
    }

    public function test_worker_gets_an_error_when_the_provider_has_no_key(): void
    {
        $task = $this->task();

        $this->postJson('/api/kanban/worker/credential', $this->exchange($task, 'claude'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Chave do Claude não configurada.');
    }

    public function test_worker_rejects_an_unknown_provider(): void
    {
        $this->postJson('/api/kanban/worker/credential', $this->exchange($this->task(), 'gemini'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider');
    }

    public function test_worker_can_exchange_repository_credential_token_for_the_project_password(): void
    {
        $task = $this->repositoryTask('rex', 'segredo-do-repo');

        $this->postJson('/api/kanban/worker/credential', $this->repositoryExchange($task))
            ->assertOk()
            ->assertJsonPath('repository_username', 'rex')
            ->assertJsonPath('repository_password', 'segredo-do-repo');
    }

    public function test_repository_credential_token_is_single_use(): void
    {
        $task = $this->repositoryTask('rex', 'segredo-do-repo');
        $body = $this->repositoryExchange($task);

        $this->postJson('/api/kanban/worker/credential', $body)->assertOk();
        $this->postJson('/api/kanban/worker/credential', $body)
            ->assertStatus(410)
            ->assertJsonPath('message', 'Credencial temporária inválida ou expirada.');
    }

    public function test_expired_repository_credential_token_is_rejected(): void
    {
        $task = $this->repositoryTask('rex', 'segredo-do-repo');
        $token = bin2hex(random_bytes(40));
        WorkerCredential::create([
            'task_id' => $task->id,
            'purpose' => WorkerCredential::PURPOSE_REPOSITORY_PASSWORD,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/kanban/worker/credential', ['token' => $token, 'task_id' => $task->id, 'purpose' => 'repository_password'])
            ->assertStatus(410);
    }

    public function test_repository_credential_exchange_requires_a_project_with_a_configured_password(): void
    {
        $project = Project::create(['name' => 'Sem senha', 'source_type' => 'repository', 'repository_provider' => 'github', 'repository_url' => 'https://github.com/acme/app.git', 'repository_auth_type' => 'none']);
        $task = Task::create(['project_id' => $project->id, 'title' => 'Executar', 'lane' => 'todo']);

        $this->postJson('/api/kanban/worker/credential', $this->repositoryExchange($task))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Credencial de repositório não configurada.');
    }

    private function task(): Task
    {
        $project = Project::create(['name' => 'Worker', 'directory' => '/worker']);

        return Task::create(['project_id' => $project->id, 'title' => 'Executar', 'lane' => 'todo']);
    }

    private function repositoryTask(string $username, string $password): Task
    {
        $project = Project::create([
            'name' => 'Worker repo', 'source_type' => 'repository', 'repository_provider' => 'github',
            'repository_url' => 'https://github.com/acme/app.git', 'repository_auth_type' => 'https',
            'repository_username' => $username, 'repository_password' => $password,
        ]);

        return Task::create(['project_id' => $project->id, 'title' => 'Executar', 'lane' => 'todo']);
    }

    /** Creates a fresh single-use worker credential and returns the exchange request body. */
    private function exchange(Task $task, ?string $provider = null): array
    {
        $token = bin2hex(random_bytes(40));
        WorkerCredential::create([
            'task_id' => $task->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinute(),
        ]);

        return array_filter(['token' => $token, 'task_id' => $task->id, 'provider' => $provider]);
    }

    /** Same as exchange(), but for the repository_password purpose. */
    private function repositoryExchange(Task $task): array
    {
        $token = bin2hex(random_bytes(40));
        WorkerCredential::create([
            'task_id' => $task->id,
            'purpose' => WorkerCredential::PURPOSE_REPOSITORY_PASSWORD,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinute(),
        ]);

        return ['token' => $token, 'task_id' => $task->id, 'purpose' => 'repository_password'];
    }
}
