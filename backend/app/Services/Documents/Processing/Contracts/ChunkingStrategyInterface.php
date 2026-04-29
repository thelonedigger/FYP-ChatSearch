<?php

namespace App\Services\Documents\Processing\Contracts;

/**
 * Contract for text chunking strategies.
 *
 * Each implementation defines a distinct approach to splitting document
 * text into semantically meaningful chunks for embedding and retrieval.
 */
interface ChunkingStrategyInterface
{
    /**
     * Split text into an array of chunk data arrays.
     *
     * Each chunk contains: content, chunk_index, start_position, end_position, metadata.
     *
     * @return array<int, array{content: string, chunk_index: int, start_position: int, end_position: int, metadata: array}>
     */
    public function chunk(string $text): array;

    /**
     * Unique identifier for this strategy.
     */
    public function getName(): string;
}