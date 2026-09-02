<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\File;

return new class extends Migration
{
    public function up(): void
    {
        $source = storage_path('app/public/project-logos');
        $destination = public_path('project-logos');

        if (File::isDirectory($source)) {
            File::ensureDirectoryExists($destination);
            File::copyDirectory($source, $destination);
        }
    }

    public function down(): void
    {
        // As imagens são preservadas para evitar perda de arquivos em rollback.
    }
};
