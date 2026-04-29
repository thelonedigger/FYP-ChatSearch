<?php

namespace App\Services\Search\DataRetrieval\Providers;

use App\Services\Search\DataRetrieval\Contracts\RerankerProviderInterface;
use Illuminate\Support\Facades\Http;

class JinaRerankerProvider implements RerankerProviderInterface
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = config('text_processing.jina.api_key');
        $this->baseUrl = config('text_processing.jina.base_url');
        $this->model = config('text_processing.reranker.providers.jina.model');
        $this->timeout = config('text_processing.jina.timeout', 30);
    }

    public function rerank(string $query, array $documents, int $topN): array
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

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    public function getModel(): string
    {
        return $this->model;
    }
}