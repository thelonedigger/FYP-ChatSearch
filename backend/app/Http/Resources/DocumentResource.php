<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'file_size' => $this->file_size,
            'total_chunks' => $this->total_chunks,
            'metadata' => $this->metadata,
            'relevance_score' => $this->when(isset($this->relevance_score), $this->relevance_score),
            'preview_chunks' => $this->when($this->relationLoaded('textChunks'), TextChunkResource::collection($this->textChunks)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}