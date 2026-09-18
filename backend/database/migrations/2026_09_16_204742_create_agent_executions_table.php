<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('agent', 30); // developer | tester | devops
            $table->unsignedInteger('attempt')->default(1);
            $table->string('status', 30); // running | success | bug | blocked | publish_blocked
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('cached_input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('estimated_cost', 12, 6)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('prompt_version', 20)->nullable();
            $table->string('branch')->nullable();
            $table->string('commit_hash')->nullable();
            $table->string('error_type', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->string('next_suggested', 30)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'agent']);
            $table->index('status');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_executions');
    }
};
