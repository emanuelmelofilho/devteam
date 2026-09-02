<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WhatsappSettingsSeeder extends Seeder
{
    public function run()
    {
        DB::table('whatsapp_settings')->insert([
            'api_url' => env('WHATSAPP_API_URL'),
            'token' => env('WHATSAPP_TOKEN'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
