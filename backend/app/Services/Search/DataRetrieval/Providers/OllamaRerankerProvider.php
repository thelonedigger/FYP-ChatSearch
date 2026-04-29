<?php

namespace App\Services\Search\DataRetrieval\Providers;

use App\Services\Search\DataRetrieval\Contracts\RerankerProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaRerankerProvider implements RerankerProviderInterface
{
    private string $baseUrl;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('text_processing.ollama.base_url', 'http://ollama:11434'), '/');
        $this->model = config('text_processing.reranker.providers.ollama.model');
        $this->timeout = config('text_processing.reranker.providers.ollama.timeout', 120);
    }

    public function rerank(string $query, array $documents, int $topN): array
    {
        $total = count($documents);
        $scored = [];

        foreach ($documents as $index => $document) {
            $relevance = $this->scoreDocument($query, $document);
            $positionalTiebreaker = (1.0 - $index / max($total, 1)) * 0.001;
            $scored[] = [
                'index' => $index,
                'relevance_score' => $relevance + $positionalTiebreaker,
            ];
        }

        usort($scored, fn($a, $b) => $b['relevance_score'] <=> $a['relevance_score']);

        return array_slice($scored, 0, min($topN, $total));
    }

    public function isAvailable(): bool
    {
        try {
            return Http::timeout(3)->get("{$this->baseUrl}/api/tags")->successful();
        } catch (\Exception) {
            return false;
        }
    }

    public function getModel(): string
    {
        return $this->model;
    }

    private function scoreDocument(string $query, string $document): float
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/api/chat", [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Judge whether the document is relevant to the search query. Answer only "yes" or "no".',
                        ],
                        [
                            'role' => 'user',
                            'content' => "<query>{$query}</query>\n<document>{$document}</document>",
                        ],
                    ],
                    'stream' => false,
                    'options' => [
                        'temperature' => 0.0,
                        'num_predict' => 64,
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('Ollama reranker request failed for document', ['status' => $response->status()]);
                return 0.0;
            }

            $content = strtolower(trim($response->json('message.content', '')));

            if (str_contains($content, '</think>')) {
                $content = trim(substr($content, strrpos($content, '</think>') + 8));
            }

            return str_starts_with($content, 'yes') ? 1.0 : 0.0;
        } catch (\Exception $e) {
            Log::warning('Ollama reranker scoring failed', ['error' => $e->getMessage()]);
            return 0.0;
        }
    }
}