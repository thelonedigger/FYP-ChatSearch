<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS text_chunks_content_trgm_idx ON text_chunks USING gin (content gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS documents_filename_trgm_idx ON documents USING gin (filename gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS text_chunks_content_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS documents_filename_trgm_idx');
    }
};