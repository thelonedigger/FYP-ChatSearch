<?php

namespace App\Services\Documents\Processing\Providers;

use App\Services\Documents\Processing\Contracts\EmbeddingProviderInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

class JinaEmbeddingProvider implements EmbeddingProviderInterface
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private int $dimensions;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = config('text_processing.jina.api_key') ?: throw new \RuntimeException('JINA_API_KEY required');
        $this->baseUrl = config('text_processing.jina.base_url');
        $this->model = config('text_processing.embeddings.providers.jina.model');
        $this->dimensions = config('text_processing.embeddings.dimensions');
        $this->timeout = config('text_processing.jina.timeout');
    }

    public function generateEmbeddings(array $texts, string $task = 'retrieval.passage'): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])
        ->timeout($this->timeout)
        ->post("{$this->baseUrl}/embeddings", [
            'model' => $this->model,
            'input' => $texts,
            'task' => $task,
            'dimensions' => $this->dimensions,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Jina embedding request failed: ' . $response->body());
        }

        $data = $response->json('data', []);
        usort($data, fn($a, $b) => $a['index'] <=> $b['index']);

        return array_map(fn($item) => $item['embedding'] ?? [], $data);
    }

    public function generateEmbeddingsAsync(array $texts, string $task = 'retrieval.passage'): PromiseInterface
    {
        $client = new GuzzleClient([
            'timeout' => $this->timeout,
            'connect_timeout' => 5,
        ]);

        return $client->postAsync("{$this->baseUrl}/embeddings", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->model,
                'input' => $texts,
                'task' => $task,
                'dimensions' => $this->dimensions,
            ],
        ])->then(function ($response) {
            $body = json_decode($response->getBody()->getContents(), true);
            $data = $body['data'] ?? [];
            usort($data, fn($a, $b) => $a['index'] <=> $b['index']);
            return array_map(fn($item) => $item['embedding'] ?? [], $data);
        });
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    public function getModel(): string
    {
        return $this->model;
    }
}