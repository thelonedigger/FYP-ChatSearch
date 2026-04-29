<?php

namespace App\Services\Documents\Processing\Stages;

use App\Models\Document;
use App\Models\ProcessingTask;
use App\Services\Documents\Processing\Contracts\PipelineStageInterface;
use App\Services\Documents\Processing\DTOs\PipelineContext;
use App\Services\Documents\Processing\Exceptions\PipelineStageException;

class ValidationStage implements PipelineStageInterface
{
    private int $minLen;
    public function __construct() { $this->minLen = config('text_processing.chunking.min_chunk_size', 100); }
    public function getName(): string { return ProcessingTask::STAGE_VALIDATION; }

    public function process(PipelineContext $ctx): PipelineContext
    {
        $len = strlen($ctx->content ?? '');
        if ($len < $this->minLen) {
            throw PipelineStageException::validationFailed("Content too short: {$len} chars (min: {$this->minLen})");
        }
        $hash = Document::hashContent($ctx->content);
        $existing = Document::where('file_hash', $hash)->first();

        if ($existing && !($ctx->task->options['force'] ?? false)) {
            throw PipelineStageException::validationFailed("Duplicate content. Document exists with ID: {$existing->id}");
        }

        $ctx->validationResults = [
            'content_length'  => ['passed' => true, 'value' => $len],
            'duplicate_check' => ['passed' => true, 'note' => $existing ? 'Force reprocessing enabled' : null],
            'encoding'        => ['passed' => ($enc = mb_detect_encoding($ctx->content, ['UTF-8', 'ISO-8859-1', 'ASCII'], true)) !== false, 'detected' => $enc ?: 'unknown'],
        ];
        $ctx->metadata['content_hash'] = $hash;

        return $ctx->setStageData($this->getName(), ['content_hash' => $hash, 'validations' => $ctx->validationResults]);
    }

    public function shouldSkip(PipelineContext $ctx): bool { return !empty($ctx->validationResults); }
    public function rollback(PipelineContext $ctx): void { $ctx->validationResults = []; }
}