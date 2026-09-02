<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('frontend')->nullable()->after('description');
            $table->string('backend')->nullable()->after('frontend');
            $table->string('database')->nullable()->after('backend');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['frontend', 'backend', 'database']);
        });
    }
};
