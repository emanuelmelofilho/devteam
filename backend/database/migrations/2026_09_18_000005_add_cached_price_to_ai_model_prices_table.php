<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $table) {
            // Price of input tokens served from the provider's prompt cache; null = same as input.
            $table->decimal('cached_input_price_per_million', 12, 6)->nullable()->after('input_price_per_million');
        });
    }

    public function down(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $table) {
            $table->dropColumn('cached_input_price_per_million');
        });
    }
};
