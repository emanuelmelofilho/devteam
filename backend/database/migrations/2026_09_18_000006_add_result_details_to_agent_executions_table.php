<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_executions', function (Blueprint $table) {
            // What the agent reported (summary, the approach it took) and the evidence the worker
            // read from git afterwards (commit message, files touched, the patch itself).
            $table->text('summary')->nullable()->after('status');
            $table->text('solution')->nullable()->after('summary');
            $table->text('commit_message')->nullable()->after('commit_hash');
            $table->json('files')->nullable()->after('commit_message');
            $table->longText('patch')->nullable()->after('files');
        });
    }

    public function down(): void
    {
        Schema::table('agent_executions', function (Blueprint $table) {
            $table->dropColumn(['summary', 'solution', 'commit_message', 'files', 'patch']);
        });
    }
};
