<?php

namespace App\Services\Search\DataRetrieval;

use App\Services\Search\DataRetrieval\Contracts\RerankerProviderInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class RerankerService
{
    private bool $enabled;
    private int $topK;
    private int $returnTopK;

    public function __construct(private RerankerProviderInterface $provider)
    {
        $this->enabled = config('text_processing.reranker.enabled', true);
        $this->topK = config('text_processing.reranker.top_k', 50);
        $this->returnTopK = config('text_processing.reranker.return_top_k', 10);
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->provider->isAvailable();
    }

    public function rerankChunks(string $query, Collection $chunks, ?int $returnTopK = null): Collection
    {
        if ($chunks->isEmpty() || !$this->isEnabled()) {
            return $chunks;
        }

        $returnTopK ??= $this->returnTopK;
        $chunksToRerank = $chunks->take($this->topK);

        try {
            $rerankedIndices = $this->provider->rerank(
                $query,
                $chunksToRerank->pluck('content')->toArray(),
                $returnTopK
            );

            return $this->buildRerankedCollection($chunksToRerank, $rerankedIndices);
        } catch (\Exception $e) {
            Log::error('Reranking failed', ['error' => $e->getMessage()]);
            return $chunks->take($returnTopK);
        }
    }

    public function rerank(string $query, array $documents, ?int $returnTopK = null): array
    {
        if (empty($documents)) {
            return [];
        }

        if (!$this->isEnabled()) {
            return array_map(
                fn($doc, $idx) => ['index' => $idx, 'score' => 0, 'document' => $doc],
                $documents,
                array_keys($documents)
            );
        }

        $texts = array_map(
            fn($doc) => is_string($doc) ? $doc : ($doc['content'] ?? $doc->content ?? ''),
            $documents
        );

        $results = $this->provider->rerank($query, $texts, $returnTopK ?? $this->returnTopK);

        return array_map(
            fn($r) => [
                'index' => $r['index'],
                'score' => $r['relevance_score'],
                'document' => $documents[$r['index']],
            ],
            $results
        );
    }

    public function getModel(): string
    {
        return $this->provider->getModel();
    }

    private function buildRerankedCollection(Collection $originalChunks, array $rerankedIndices): Collection
    {
        $chunksArray = $originalChunks->values()->all();

        return new Collection(
            collect($rerankedIndices)->map(function ($result) use ($chunksArray) {
                if (!isset($chunksArray[$result['index']])) {
                    return null;
                }

                $chunk = $chunksArray[$result['index']];
                $chunk->rerank_score = $result['relevance_score'];
                $chunk->relevance_score = $result['relevance_score'];
                $chunk->search_strategy = ($chunk->search_strategy ?? 'unknown') . '+reranked';

                if (isset($chunk->fusion_score)) {
                    $chunk->fusion_score = $result['relevance_score'];
                }

                return $chunk;
            })->filter()->all()
        );
    }
}