<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials_returns_token()
    {
        $user = User::factory()->create(['email' => 'test@example.com', 'password' => bcrypt('secret')]);

        $response = $this->postJson('/api/auth/login', ['email' => 'test@example.com', 'password' => 'secret']);
        $response->assertStatus(200);
        $response->assertJsonStructure(['token','user']);
    }

    public function test_logout_revokes_token()
    {
        $user = User::factory()->create(['password' => bcrypt('secret')]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/auth/logout');
        $response->assertStatus(200);
    }
}
