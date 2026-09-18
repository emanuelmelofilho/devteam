<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedInteger('attempt')->default(1)->after('version');
            $table->unsignedInteger('max_attempts')->default(3)->after('attempt');
            $table->foreignId('origin_task_id')->nullable()->after('reporter_id')->constrained('tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_task_id');
            $table->dropColumn(['attempt', 'max_attempts']);
        });
    }
};
