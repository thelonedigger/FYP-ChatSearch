<?php

namespace App\Services\Documents\Processing\Providers;

use App\Services\Documents\Processing\Contracts\EmbeddingProviderInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

class OllamaEmbeddingProvider implements EmbeddingProviderInterface
{
    private string $baseUrl;
    private string $model;
    private int $dimensions;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('text_processing.ollama.base_url', 'http://ollama:11434'), '/');
        $this->model = config('text_processing.embeddings.providers.ollama.model');
        $this->dimensions = config('text_processing.embeddings.dimensions');
        $this->timeout = config('text_processing.embeddings.providers.ollama.timeout', 120);
    }

    public function generateEmbeddings(array $texts, string $task = 'retrieval.passage'): array
    {
        $response = Http::timeout($this->timeout)
            ->post("{$this->baseUrl}/api/embed", [
                'model' => $this->model,
                'input' => $texts,
            ]);

        if (!$response->successful()) {
            throw new \Exception('Ollama embedding request failed: ' . $response->body());
        }

        return $response->json('embeddings', []);
    }

    public function generateEmbeddingsAsync(array $texts, string $task = 'retrieval.passage'): PromiseInterface
    {
        $client = new GuzzleClient([
            'timeout' => $this->timeout,
            'connect_timeout' => 5,
        ]);

        return $client->postAsync("{$this->baseUrl}/api/embed", [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [
                'model' => $this->model,
                'input' => $texts,
            ],
        ])->then(function ($response) {
            $body = json_decode($response->getBody()->getContents(), true);
            return $body['embeddings'] ?? [];
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