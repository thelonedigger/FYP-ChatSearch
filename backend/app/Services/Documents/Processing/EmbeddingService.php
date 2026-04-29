<?php

namespace App\Services\Documents\Processing;

use App\Services\Documents\Processing\Contracts\EmbeddingProviderInterface;
use GuzzleHttp\Promise\PromiseInterface;

class EmbeddingService
{
    private int $batchSize;

    public function __construct(private EmbeddingProviderInterface $provider)
    {
        $this->batchSize = config('text_processing.embeddings.batch_size', 100);
    }

    public function generateQueryEmbeddingAsync(string $query): PromiseInterface
    {
        return $this->provider->generateEmbeddingsAsync([$query], 'retrieval.query')
            ->then(fn(array $embeddings) => $embeddings[0] ?? []);
    }

    public function generateEmbeddings(array $texts, string $task = 'retrieval.passage'): array
    {
        if (empty($texts)) {
            return [];
        }

        $embeddings = [];
        foreach (array_chunk($texts, $this->batchSize) as $batch) {
            $embeddings = array_merge($embeddings, $this->provider->generateEmbeddings($batch, $task));
        }

        return $embeddings;
    }

    public function generateSingleEmbedding(string $text): array
    {
        return $this->generateEmbeddings([$text], 'retrieval.passage')[0] ?? [];
    }

    public function generateQueryEmbedding(string $query): array
    {
        return $this->generateEmbeddings([$query], 'retrieval.query')[0] ?? [];
    }

    public function getDimensions(): int
    {
        return $this->provider->getDimensions();
    }

    public function getModel(): string
    {
        return $this->provider->getModel();
    }
}