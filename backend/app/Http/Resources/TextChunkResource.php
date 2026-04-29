<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TextChunkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'highlighted_content' => $this->when(isset($this->highlighted_content), $this->highlighted_content),
            'chunk_index' => $this->chunk_index,
            'word_count' => $this->word_count,
            'relevance_score' => $this->when(isset($this->relevance_score), $this->relevance_score),
            'search_strategy' => $this->when(isset($this->search_strategy), $this->search_strategy),
            'fusion_score' => $this->when(isset($this->fusion_score), $this->fusion_score),
            'ranking_details' => $this->when(isset($this->ranking_details), $this->ranking_details),
            'similarity' => $this->when(isset($this->similarity), $this->similarity),
            'trigram_similarity' => $this->when(isset($this->trigram_similarity), $this->trigram_similarity),
            'metadata' => $this->metadata,
            'document' => new DocumentResource($this->whenLoaded('document')),
        ];
    }
}