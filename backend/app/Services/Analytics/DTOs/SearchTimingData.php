<?php

namespace App\Services\Analytics\DTOs;

class SearchTimingData
{
    public ?float $vectorTimeMs = null, $trigramTimeMs = null, $fusionTimeMs = null;
    public ?float $rerankTimeMs = null, $llmTimeMs = null, $intentClassificationTimeMs = null, $totalTimeMs = null;
    public ?float $embeddingTimeMs = null;
    private float $startTime;

    public function __construct() { $this->startTime = microtime(true); }
    public function finalize(): void { $this->totalTimeMs = round((microtime(true) - $this->startTime) * 1000, 2); }

    public function toArray(): array
    {
        return array_combine(
            ['embedding_time_ms', 'vector_time_ms', 'trigram_time_ms', 'fusion_time_ms', 'rerank_time_ms', 'llm_time_ms', 'intent_classification_time_ms', 'total_time_ms'],
            [$this->embeddingTimeMs, $this->vectorTimeMs, $this->trigramTimeMs, $this->fusionTimeMs, $this->rerankTimeMs, $this->llmTimeMs, $this->intentClassificationTimeMs, $this->totalTimeMs]
        );
    }
}