<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE INDEX IF NOT EXISTS text_chunks_content_fts_idx "
            . "ON text_chunks USING gin (to_tsvector('english', content))"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS text_chunks_content_fts_idx');
    }
};