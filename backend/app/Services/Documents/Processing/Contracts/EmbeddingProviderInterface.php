<?php

namespace App\Services\Documents\Processing\Contracts;

use GuzzleHttp\Promise\PromiseInterface;

interface EmbeddingProviderInterface
{
    public function generateEmbeddings(array $texts, string $task = 'retrieval.passage'): array;

    public function generateEmbeddingsAsync(array $texts, string $task = 'retrieval.passage'): PromiseInterface;

    public function getDimensions(): int;

    public function getModel(): string;
}