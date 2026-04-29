<?php

namespace App\Services\Search\DataRetrieval;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JinaRerankerService
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private bool $enabled;
    private int $topK;
    private int $returnTopK;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = config('text_processing.jina.api_key');
        $this->baseUrl = config('text_processing.jina.base_url');
        $this->model = config('text_processing.reranker.model');
        $this->enabled = config('text_processing.reranker.enabled', true);
        $this->topK = config('text_processing.reranker.top_k', 50);
        $this->returnTopK = config('text_processing.reranker.return_top_k', 10);
        $this->timeout = config('text_processing.jina.timeout', 30);
    }

    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->apiKey);
    }

    public function rerankChunks(string $query, Collection $chunks, ?int $returnTopK = null): Collection
    {
        if ($chunks->isEmpty() || !$this->isEnabled()) {
            return $chunks;
        }

        $returnTopK ??= $this->returnTopK;
        $chunksToRerank = $chunks->take($this->topK);

        try {
            $rerankedIndices = $this->callRerankerApi($query, $chunksToRerank->pluck('content')->toArray(), $returnTopK);
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
            return array_map(fn($doc, $idx) => ['index' => $idx, 'score' => 0, 'document' => $doc], $documents, array_keys($documents));
        }

        $texts = array_map(fn($doc) => is_string($doc) ? $doc : ($doc['content'] ?? $doc->content ?? ''), $documents);
        $results = $this->callRerankerApi($query, $texts, $returnTopK ?? $this->returnTopK);

        return array_map(fn($r) => ['index' => $r['index'], 'score' => $r['relevance_score'], 'document' => $documents[$r['index']]], $results);
    }

    private function callRerankerApi(string $query, array $documents, int $topN): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])
        ->timeout($this->timeout)
        ->post("{$this->baseUrl}/rerank", [
            'model' => $this->model,
            'query' => $query,
            'documents' => $documents,
            'top_n' => min($topN, count($documents)),
            'return_documents' => false,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Jina reranker request failed: ' . $response->body());
        }

        return $response->json('results', []);
    }

    private function buildRerankedCollection(Collection $originalChunks, array $rerankedIndices): Collection
    {
        $chunksArray = $originalChunks->values()->all();
        
        return new Collection(collect($rerankedIndices)->map(function ($result) use ($chunksArray) {
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
        })->filter()->all());
    }

    public function getModel(): string
    {
        return $this->model;
    }
}