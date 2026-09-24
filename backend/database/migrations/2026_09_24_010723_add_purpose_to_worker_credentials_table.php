<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_credentials', function (Blueprint $table) {
            // Which secret this one-time credential unlocks; 'provider_api_key' is the default so every
            // row created so far (all for the AI provider key) keeps its current meaning.
            $table->string('purpose', 30)->default('provider_api_key')->after('task_id');
        });
    }

    public function down(): void
    {
        Schema::table('worker_credentials', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
