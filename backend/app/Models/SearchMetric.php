<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SearchMetric extends Model
{
    protected $fillable = [
        'session_id', 'user_id', 'query', 'query_hash', 'intent', 'search_type', 'results_count',
        'vector_time_ms', 'trigram_time_ms', 'fusion_time_ms', 'rerank_time_ms', 'llm_time_ms',
        'intent_classification_time_ms', 'total_time_ms', 'top_relevance_score', 'avg_relevance_score',
    ];

    protected $casts = [
        'vector_time_ms' => 'float', 'trigram_time_ms' => 'float', 'fusion_time_ms' => 'float',
        'rerank_time_ms' => 'float', 'llm_time_ms' => 'float', 'intent_classification_time_ms' => 'float',
        'total_time_ms' => 'float', 'top_relevance_score' => 'float', 'avg_relevance_score' => 'float',
    ];

    public function interactions(): HasMany { return $this->hasMany(ResultInteraction::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public static function hashQuery(string $query): string { return hash('sha256', mb_strtolower(trim($query))); }
}