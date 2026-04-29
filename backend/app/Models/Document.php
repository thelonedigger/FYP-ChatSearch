<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends BaseSearchableEntity
{
    protected $fillable = ['filename', 'filepath', 'content', 'file_hash', 'total_chunks', 'metadata'];
    protected $casts = ['metadata' => 'array'];

    /**
     * Canonical hash of a document's textual content. This is what we store in
     * `file_hash` for duplicate detection — keep this method as the single
     * source of truth for the algorithm so callers stay in sync.
     */
    public static function hashContent(string $content): string
    {
        return hash('sha256', $content);
    }

    public function textChunks(): HasMany
    {
        return $this->hasMany(TextChunk::class);
    }

    public function chunks(): HasMany
    {
        return $this->textChunks();
    }

    public function getFileSizeAttribute(): int
    {
        return strlen($this->content);
    }

    public function getEntityType(): string
    {
        return 'document';
    }

    public function getSearchableContent(): string
    {
        return $this->content;
    }

    public function getEntityMetadata(): array
    {
        return array_merge(parent::getEntityMetadata(), [
            'filename'  => $this->filename,
            'filepath'  => $this->filepath,
            'file_size' => $this->file_size,
            'file_hash' => $this->file_hash,
        ]);
    }
}