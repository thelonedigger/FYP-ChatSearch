<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->index();
            $table->text('query');
            $table->string('query_hash', 64)->index(); // For popularity aggregation
            $table->string('intent')->nullable()->index();
            $table->string('search_type')->index(); // hybrid, vector, trigram
            $table->integer('results_count');
            $table->float('vector_time_ms')->nullable();
            $table->float('trigram_time_ms')->nullable();
            $table->float('fusion_time_ms')->nullable();
            $table->float('rerank_time_ms')->nullable();
            $table->float('llm_time_ms')->nullable();
            $table->float('intent_classification_time_ms')->nullable();
            $table->float('total_time_ms');
            $table->float('top_relevance_score')->nullable();
            $table->float('avg_relevance_score')->nullable();
            
            $table->timestamps();
            
            $table->index('created_at');
            $table->index(['search_type', 'created_at']);
        });
        Schema::create('result_interactions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->index();
            $table->foreignId('search_metric_id')->constrained()->cascadeOnDelete();
            $table->foreignId('text_chunk_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('interaction_type')->default('click'); // click, expand, copy
            $table->integer('result_position'); // Position in results list
            $table->float('relevance_score')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->index(['session_id', 'created_at']);
            $table->index('interaction_type');
        });
        Schema::create('system_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('metric_type')->index(); // embedding_generation, document_processing, etc.
            $table->string('metric_name');
            $table->float('value');
            $table->string('unit')->nullable(); // ms, count, bytes, percent
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->index(['metric_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_interactions');
        Schema::dropIfExists('system_metrics');
        Schema::dropIfExists('search_metrics');
    }
};