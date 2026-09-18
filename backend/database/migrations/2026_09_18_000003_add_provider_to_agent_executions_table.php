<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_executions', function (Blueprint $table) {
            $table->string('provider', 20)->nullable()->after('agent')->index();
        });

        // Executions that predate the column: Claude runs are recognisable by their model
        // name, everything else finished on Codex. Runs still in flight were dispatched
        // under whichever provider is selected now.
        DB::table('agent_executions')->whereRaw("LOWER(model) LIKE 'claude%'")->update(['provider' => 'claude']);
        DB::table('agent_executions')->whereNull('provider')->whereNotNull('finished_at')->update(['provider' => 'codex']);
        $current = DB::table('agent_provider_settings')->orderByDesc('id')->value('provider') ?? 'codex';
        DB::table('agent_executions')->whereNull('provider')->update(['provider' => $current]);
    }

    public function down(): void
    {
        Schema::table('agent_executions', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
