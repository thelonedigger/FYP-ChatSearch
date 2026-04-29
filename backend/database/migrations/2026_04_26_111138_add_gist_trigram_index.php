<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX IF NOT EXISTS text_chunks_content_trgm_gist_idx '
            . 'ON text_chunks USING gist (content gist_trgm_ops)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS text_chunks_content_trgm_gist_idx');
    }
};