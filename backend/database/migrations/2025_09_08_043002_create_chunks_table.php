<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Jina embeddings v3 use 1024 dimensions by default.
     */
    private const EMBEDDING_DIMENSIONS = 1024;

    public function up(): void
    {
        Schema::create('text_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->onDelete('cascade');
            $table->text('content');
            $table->integer('chunk_index');
            $table->integer('start_position');
            $table->integer('end_position');
            $table->vector('embedding', self::EMBEDDING_DIMENSIONS);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'chunk_index']);
        });
        DB::statement('CREATE INDEX text_chunks_embedding_hnsw_idx ON text_chunks USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('text_chunks');
    }
};