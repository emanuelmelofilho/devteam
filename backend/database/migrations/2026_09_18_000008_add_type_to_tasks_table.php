<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // What kind of change the task is; decides which part of the version DevOps bumps.
            // 'fix' is the default so existing tasks keep the old patch-bump behavior.
            $table->string('type', 20)->default('fix')->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
