<?php

namespace Database\Seeders;

use App\Models\AiModelPrice;
use Illuminate\Database\Seeder;

/**
 * USD per million tokens, from the providers' public pricing pages (checked 2026-09-18).
 * Claude runs report their own cost, so these rows matter mostly for Codex/OpenAI runs and as
 * a fallback. Model names are matched as prefixes, so `claude-haiku-4-5` also covers the dated
 * `claude-haiku-4-5-20251001`. Update by adding a row with a new `valid_from`, not by editing.
 *
 * Run with: php artisan db:seed --class=AiModelPriceSeeder
 */
class AiModelPriceSeeder extends Seeder
{
    private const VALID_FROM = '2026-09-18';

    private const PRICES = [
        // model => [input, cached input, output]
        'claude-haiku-4-5' => [1.00, 0.10, 5.00],
        'claude-sonnet-5' => [2.00, 0.20, 10.00],
        'claude-opus-5' => [5.00, 0.50, 25.00],
        'claude-fable-5-1' => [10.00, 0.25, 50.00],
        'gpt-5' => [1.25, 0.125, 10.00],
        'gpt-5-mini' => [0.25, 0.025, 2.00],
        'gpt-5-nano' => [0.05, 0.005, 0.40],
        'gpt-5.1' => [1.25, 0.125, 10.00],
        'gpt-5.2' => [1.75, 0.175, 14.00],
        'gpt-5.3-codex' => [1.75, 0.175, 14.00],
    ];

    public function run(): void
    {
        foreach (self::PRICES as $model => [$input, $cachedInput, $output]) {
            AiModelPrice::updateOrCreate(
                ['model' => $model, 'valid_from' => self::VALID_FROM],
                [
                    'input_price_per_million' => $input,
                    'cached_input_price_per_million' => $cachedInput,
                    'output_price_per_million' => $output,
                ],
            );
        }
    }
}
