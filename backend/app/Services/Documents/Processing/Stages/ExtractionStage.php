<?php

namespace App\Services\Documents\Processing\Stages;

use App\Models\ProcessingTask;
use App\Services\Documents\Processing\Contracts\PipelineStageInterface;
use App\Services\Documents\Processing\DTOs\PipelineContext;
use App\Services\Documents\Processing\Exceptions\PipelineStageException;
use App\Services\Documents\FileIngestion\FileIngestionService;
use App\Services\Documents\FileIngestion\FileIngestionException;

class ExtractionStage implements PipelineStageInterface
{
    public function __construct(private FileIngestionService $fileIngestionService) {}
    public function getName(): string { return ProcessingTask::STAGE_EXTRACTION; }

    public function process(PipelineContext $ctx): PipelineContext
    {
        try {
            $r = $this->fileIngestionService->ingest($ctx->filePath);
            $ctx->content = $r->content;
            $ctx->metadata = array_merge($ctx->metadata, $r->metadata);
            return $ctx->setStageData($this->getName(), ['content_length' => $r->getContentLength(), 'word_count' => $r->getWordCount(), 'file_type' => $r->metadata['file_type'] ?? 'unknown']);
        } catch (FileIngestionException $e) { throw PipelineStageException::extractionFailed($e->getMessage(), $e); }
    }

    public function shouldSkip(PipelineContext $ctx): bool { return $ctx->hasContent(); }
    public function rollback(PipelineContext $ctx): void { $ctx->content = null; $ctx->metadata = []; }
}