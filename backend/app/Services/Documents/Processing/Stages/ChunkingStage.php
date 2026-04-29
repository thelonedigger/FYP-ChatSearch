<?php

namespace App\Services\Documents\Processing\Stages;

use App\Models\ProcessingTask;
use App\Services\Documents\Processing\Contracts\PipelineStageInterface;
use App\Services\Documents\Processing\DTOs\PipelineContext;
use App\Services\Documents\Processing\Exceptions\PipelineStageException;
use App\Services\Documents\Processing\TextChunkingService;

class ChunkingStage implements PipelineStageInterface
{
    public function __construct(private TextChunkingService $chunkingService) {}
    public function getName(): string { return ProcessingTask::STAGE_CHUNKING; }

    public function process(PipelineContext $ctx): PipelineContext
    {
        if (!$ctx->hasContent()) throw PipelineStageException::chunkingFailed('No content available');
        try {
            if (empty($ctx->chunks = $this->chunkingService->chunkText($ctx->content))) throw PipelineStageException::chunkingFailed('No chunks generated');
            return $ctx->setStageData($this->getName(), ['chunks_created' => count($ctx->chunks), 'avg_chunk_size' => (int) round(array_sum(array_map(fn($c) => strlen($c['content'] ?? ''), $ctx->chunks)) / count($ctx->chunks))]);
        } catch (PipelineStageException $e) { throw $e; } catch (\Exception $e) { throw PipelineStageException::chunkingFailed($e->getMessage(), $e); }
    }

    public function shouldSkip(PipelineContext $ctx): bool { return $ctx->hasChunks(); }
    public function rollback(PipelineContext $ctx): void { $ctx->chunks = []; }
}