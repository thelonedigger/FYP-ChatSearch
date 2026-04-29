<?php

namespace App\Services\Search\DataRetrieval\Contracts;

interface RerankerProviderInterface
{
    /**
     * @return array<int, array{index: int, relevance_score: float}>
     */
    public function rerank(string $query, array $documents, int $topN): array;

    public function isAvailable(): bool;

    public function getModel(): string;
}