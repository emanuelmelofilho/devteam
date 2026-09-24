<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // 'local' is the default so every already-registered project keeps working without a data migration.
            $table->string('source_type', 20)->default('local')->after('directory');
            $table->string('repository_provider', 20)->nullable()->after('source_type');
            $table->string('repository_url', 2048)->nullable()->after('repository_provider');
            $table->string('repository_auth_type', 10)->nullable()->after('repository_url');
            // Not a secret on its own (just a login), only meaningful together with repository_password.
            $table->string('repository_username')->nullable()->after('repository_auth_type');
            // Password or personal access token for HTTPS; `encrypted` cast on the model protects it at
            // rest, same pattern as ClaudeSetting::$casts['api_key'].
            $table->text('repository_password')->nullable()->after('repository_username');
            // Only the path to an SSH key that already exists on the worker host, never the key content —
            // same trust level as `directory` today, so it stays plain (not encrypted, not hidden).
            $table->string('repository_ssh_key_path', 1024)->nullable()->after('repository_password');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'source_type', 'repository_provider', 'repository_url', 'repository_auth_type',
                'repository_username', 'repository_password', 'repository_ssh_key_path',
            ]);
        });
    }
};
