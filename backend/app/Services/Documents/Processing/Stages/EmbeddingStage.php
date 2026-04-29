<?php

namespace App\Services\Documents\Processing\Stages;

use App\Models\ProcessingTask;
use App\Services\Documents\Processing\Contracts\PipelineStageInterface;
use App\Services\Documents\Processing\DTOs\PipelineContext;
use App\Services\Documents\Processing\Exceptions\PipelineStageException;
use App\Services\Documents\Processing\EmbeddingService;

class EmbeddingStage implements PipelineStageInterface
{
    public function __construct(private EmbeddingService $embeddingService) {}
    public function getName(): string { return ProcessingTask::STAGE_EMBEDDING; }

    public function process(PipelineContext $ctx): PipelineContext
    {
        if (!$ctx->hasChunks()) throw PipelineStageException::embeddingFailed('No chunks available');
        try {
            $start = microtime(true);
            $ctx->embeddings = $this->embeddingService->generateEmbeddings(array_column($ctx->chunks, 'content'));
            if (count($ctx->embeddings) !== count($ctx->chunks)) throw PipelineStageException::embeddingFailed("Count mismatch: expected " . count($ctx->chunks) . ", got " . count($ctx->embeddings));
            return $ctx->setStageData($this->getName(), ['embeddings_generated' => count($ctx->embeddings), 'embedding_dimensions' => count($ctx->embeddings[0] ?? []), 'duration_ms' => round((microtime(true) - $start) * 1000, 2)]);
        } catch (PipelineStageException $e) { throw $e; } catch (\Exception $e) { throw PipelineStageException::embeddingFailed($e->getMessage(), $e); }
    }

    public function shouldSkip(PipelineContext $ctx): bool { return $ctx->hasEmbeddings(); }
    public function rollback(PipelineContext $ctx): void { $ctx->embeddings = []; }
}