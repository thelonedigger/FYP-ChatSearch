<?php

namespace App\Services\Documents\Processing\DTOs;

use App\Models\ProcessingTask;

class PipelineContext
{
    public function __construct(
        public ProcessingTask $task, public ?string $filePath = null, public ?string $content = null,
        public array $metadata = [], public array $chunks = [], public array $embeddings = [],
        public ?int $documentId = null, public array $validationResults = [], public array $stageData = [],
    ) { $this->filePath ??= $task->filepath; }

    public function setStageData(string $s, array $d): self { $this->stageData[$s] = $d; return $this; }
    public function getStageData(string $s): array { return $this->stageData[$s] ?? []; }
    public function hasContent(): bool { return !empty($this->content); }
    public function hasChunks(): bool { return !empty($this->chunks); }
    public function hasEmbeddings(): bool { return !empty($this->embeddings); }
}