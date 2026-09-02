<?php

use App\Models\ActivityLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('scope', 30)->default('project')->after('user_id')->index();
        });

        ActivityLog::create([
            'scope' => 'devteam',
            'event' => 'devteam.updated',
            'title' => 'Módulo de logs aprimorado',
            'details' => 'O histórico foi separado entre alterações do Devteam e eventos dos projetos. Também foram adicionados paginação, detalhes expansíveis, branch e hash do commit.',
            'metadata' => ['branch' => null, 'commit_hash' => null, 'version' => '0.2.9'],
        ]);
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
