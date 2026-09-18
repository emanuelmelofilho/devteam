<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('assigned_agent')->nullable()->after('lane');
            $table->string('branch')->nullable()->after('assigned_agent');
            $table->text('work_summary')->nullable()->after('branch');
            $table->string('version')->nullable()->after('work_summary');
        });
        Schema::table('task_comments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('task_id')->constrained('users')->nullOnDelete();
        });
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('token');
            $table->string('client_token')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn(['assigned_agent', 'branch', 'work_summary', 'version']));
        Schema::table('task_comments', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
        Schema::table('whatsapp_settings', fn (Blueprint $table) => $table->dropColumn(['phone', 'client_token']));
    }
};
