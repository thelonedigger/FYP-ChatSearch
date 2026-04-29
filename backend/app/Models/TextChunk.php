<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TextChunk extends Model
{
    protected $fillable = ['document_id', 'content', 'chunk_index', 'start_position', 'end_position', 'embedding', 'metadata'];
    protected $casts = ['embedding' => 'array', 'metadata' => 'array'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
    
    public function entity(): BelongsTo
    {
        return $this->document();
    }

    public function getWordCountAttribute(): int
    {
        return str_word_count($this->content);
    }
}