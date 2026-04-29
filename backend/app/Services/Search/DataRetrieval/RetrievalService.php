<?php

namespace App\Services\Search\DataRetrieval;

use App\Models\Document;
use App\Models\TextChunk;
use App\Services\Documents\Processing\EmbeddingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class RetrievalService
{
    public function __construct(
        private EmbeddingService $embeddingService,
        private RankFusionService $rankFusionService,
        private RerankerService $rerankerService
    ) {}

    public function hybridSearch(string $query, int $limit = null): Collection
    {
        $limit ??= config('text_processing.retrieval.default_limit');
        
        $vectorResults = $this->searchSimilarChunks($query, $limit, false);
        $trigramResults = $this->searchTrigramMatches($query, $limit, false);
        
        $vectorWeight = config('text_processing.search.vector_weight', 0.7);
        $trigramWeight = config('text_processing.search.trigram_weight', 0.3);
        
        $fusedResults = $this->rankFusionService->fuseRankings(
            [$vectorResults, $trigramResults],
            [$vectorWeight, $trigramWeight]
        )->take($limit);
        
        return $this->applyReranking($query, $fusedResults);
    }

    private function vectorQuery(string $embeddingJson, int $limit, ?int $documentId = null): Collection
    {
        $threshold = config('text_processing.retrieval.similarity_threshold');

        $query = TextChunk::select('text_chunks.*')
            ->selectRaw('1 - (embedding <=> ?) as similarity', [$embeddingJson])
            ->with('document:id,filename,filepath')
            ->orderByRaw('embedding <=> ?', [$embeddingJson])
            ->limit($limit * 2);

        if ($documentId !== null) {
            $query->where('document_id', $documentId);
        }

        return $query->get()
            ->filter(fn($chunk) => $chunk->similarity >= $threshold)
            ->take($limit)
            ->each(fn($chunk) => $chunk->search_strategy = 'vector')
            ->each(fn($chunk) => $chunk->relevance_score = $chunk->similarity)
            ->values();
    }

    public function searchSimilarChunks(string $query, int $limit = null, bool $applyReranking = true): Collection
    {
        $limit ??= config('text_processing.retrieval.default_limit');
        $queryEmbedding = $this->embeddingService->generateQueryEmbedding($query);
        
        if (empty($queryEmbedding)) {
            return new Collection();
        }

        $embeddingJson = json_encode($queryEmbedding);

        $results = $this->vectorQuery($embeddingJson, $limit);

        return $applyReranking ? $this->applyReranking($query, $results) : $results;
    }

    public function searchTrigramMatches(string $query, int $limit = null, bool $applyReranking = true): Collection
    {
        $limit ??= config('text_processing.retrieval.default_limit');
        $results = TextChunk::select('text_chunks.*')
            ->selectRaw("ts_rank_cd(to_tsvector('english', content), plainto_tsquery('english', ?)) as fts_score", [$query])
            ->with('document:id,filename,filepath')
            ->whereRaw("to_tsvector('english', content) @@ plainto_tsquery('english', ?)", [$query])
            ->orderByRaw("ts_rank_cd(to_tsvector('english', content), plainto_tsquery('english', ?)) DESC", [$query])
            ->limit($limit)
            ->get()
            ->each(function ($chunk) use ($query) {
                $chunk->search_strategy = 'fts';
                $chunk->trigram_similarity = $chunk->fts_score;  // Map to expected field
                $chunk->relevance_score = $chunk->fts_score;
                $chunk->highlighted_content = $this->highlightMatches($chunk->content, $query);
            });

        return $applyReranking ? $this->applyReranking($query, $results) : $results;
    }

    public function hybridSearchInDocument(int $documentId, string $query, int $limit = null): Collection
    {
        $limit ??= config('text_processing.retrieval.default_limit');
        
        $vectorResults = $this->searchChunksInDocument($documentId, $query, $limit, false);
        $trigramResults = $this->searchTrigramInDocument($documentId, $query, $limit, false);
        
        $fusedResults = $this->rankFusionService->fuseRankings(
            [$vectorResults, $trigramResults],
            [config('text_processing.search.vector_weight', 0.7), config('text_processing.search.trigram_weight', 0.3)]
        )->take($limit);
        
        return $this->applyReranking($query, $fusedResults);
    }

    public function searchChunksInDocument(int $documentId, string $query, int $limit = null, bool $applyReranking = true): Collection
    {
        $limit ??= config('text_processing.retrieval.default_limit');
        $queryEmbedding = $this->embeddingService->generateQueryEmbedding($query);
        
        if (empty($queryEmbedding)) {
            return new Collection();
        }

        $embeddingJson = json_encode($queryEmbedding);

        $results = $this->vectorQuery($embeddingJson, $limit, $documentId);

        return $applyReranking ? $this->applyReranking($query, $results) : $results;
    }

    public function searchByEmbedding(string $query, string $embeddingJson, int $limit = null, bool $applyReranking = true): Collection
    {
        $limit ??= config('text_processing.retrieval.default_limit');

        $results = $this->vectorQuery($embeddingJson, $limit);

        return $applyReranking ? $this->applyReranking($query, $results) : $results;
    }

    public function searchTrigramInDocument(int $documentId, string $query, int $limit, bool $applyReranking = true): Collection
    {
        $results = TextChunk::select('text_chunks.*')
            ->selectRaw("ts_rank_cd(to_tsvector('english', content), plainto_tsquery('english', ?)) as fts_score", [$query])
            ->where('document_id', $documentId)
            ->with('document:id,filename,filepath')
            ->whereRaw("to_tsvector('english', content) @@ plainto_tsquery('english', ?)", [$query])
            ->orderByRaw("ts_rank_cd(to_tsvector('english', content), plainto_tsquery('english', ?)) DESC", [$query])
            ->limit($limit)
            ->get()
            ->each(function ($chunk) use ($query) {
                $chunk->search_strategy = 'fts';
                $chunk->trigram_similarity = $chunk->fts_score;
                $chunk->relevance_score = $chunk->fts_score;
                $chunk->highlighted_content = $this->highlightMatches($chunk->content, $query);
            });

        return $applyReranking ? $this->applyReranking($query, $results) : $results;
    }

    private function applyReranking(string $query, Collection $results): Collection
    {
        if ($results->isEmpty() || !$this->rerankerService->isEnabled()) {
            return $results;
        }

        try {
            return $this->rerankerService->rerankChunks($query, $results, $results->count());
        } catch (\Exception $e) {
            Log::error('Reranking failed', ['error' => $e->getMessage()]);
            return $results;
        }
    }

    public function searchDocumentsByName(string $query, int $limit = null): Collection
    {
        $limit ??= config('text_processing.retrieval.default_limit');
        $threshold = config('text_processing.search.document_name_threshold', 0.1);
        
        return Document::select('documents.*')
            ->selectRaw('similarity(filename, ?) as relevance_score', [$query])
            ->whereRaw('similarity(filename, ?) > ?', [$query, $threshold])
            ->orderByRaw('similarity(filename, ?) DESC', [$query])
            ->limit($limit)
            ->with(['textChunks' => fn($q) => $q->orderBy('chunk_index')->limit(2)])
            ->get();
    }

    private function highlightMatches(string $content, string $query): string
    {
        $highlighted = $content;
        foreach (explode(' ', strtolower(trim($query))) as $word) {
            if (strlen($word) > 2) {
                $highlighted = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', '<mark>$0</mark>', $highlighted);
            }
        }
        return $highlighted;
    }
}