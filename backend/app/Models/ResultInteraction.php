<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultInteraction extends Model
{
    public $timestamps = false;
    protected $fillable = ['session_id', 'user_id', 'search_metric_id', 'text_chunk_id', 'document_id', 'interaction_type', 'result_position', 'relevance_score', 'created_at'];
    protected $casts = ['relevance_score' => 'float', 'created_at' => 'datetime'];

    protected static function booted(): void { static::creating(fn($i) => $i->created_at ??= now()); }

    public function searchMetric(): BelongsTo { return $this->belongsTo(SearchMetric::class); }
    public function textChunk(): BelongsTo { return $this->belongsTo(TextChunk::class); }
    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}