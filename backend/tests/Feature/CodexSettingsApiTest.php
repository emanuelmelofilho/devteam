<?php

namespace Tests\Feature;

use App\Models\CodexSetting;
use App\Models\User;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkerCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CodexSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_is_encrypted_and_never_returned_in_full(): void
    {
        $user = User::factory()->create();
        $key = 'sk-project-secret-value-for-testing';

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/kanban/codex-settings', [
            'api_key' => $key,
        ]);

        $response->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonMissing(['api_key' => $key]);

        $storedValue = DB::table('codex_settings')->value('api_key');
        $this->assertNotSame($key, $storedValue);
        $this->assertSame($key, CodexSetting::latest()->first()->api_key);

        $this->getJson('/api/kanban/codex-settings')
            ->assertOk()
            ->assertJsonMissing(['api_key' => $key])
            ->assertJsonPath('masked_key', '••••••••••••ting');
    }

    public function test_worker_can_exchange_a_temporary_token_only_once(): void
    {
        $user = User::factory()->create();
        $key = 'sk-project-worker-secret-for-testing';
        $this->actingAs($user, 'sanctum')->postJson('/api/kanban/codex-settings', ['api_key' => $key]);
        $project = Project::create(['name' => 'Worker', 'directory' => '/worker']);
        $task = Task::create(['project_id' => $project->id, 'title' => 'Executar', 'lane' => 'todo']);
        $token = str_repeat('a', 80);
        WorkerCredential::create([
            'task_id' => $task->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinute(),
        ]);

        $this->postJson('/api/kanban/worker/codex-credential', ['token' => $token, 'task_id' => $task->id])
            ->assertOk()->assertJsonPath('api_key', $key);
        $this->postJson('/api/kanban/worker/codex-credential', ['token' => $token, 'task_id' => $task->id])
            ->assertStatus(410)->assertJsonMissing(['api_key' => $key]);
    }
}
