<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

abstract class BaseSearchableEntity extends Model
{
    abstract public function getEntityType(): string;
    abstract public function getSearchableContent(): string;
    abstract public function chunks(): HasMany;

    public function getEntityMetadata(): array
    {
        return [
            'entity_type' => $this->getEntityType(),
            'entity_id' => $this->id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
    
    public function getContentHash(): string { return hash('sha256', $this->getSearchableContent()); }
    public function isProcessed(): bool { return $this->chunks()->exists(); }
    public function getChunkCount(): int { return $this->chunks()->count(); }
}