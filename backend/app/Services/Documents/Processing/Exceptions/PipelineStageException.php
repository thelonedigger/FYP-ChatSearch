<?php

namespace App\Services\Documents\Processing\Exceptions;

class PipelineStageException extends \Exception
{
    public function __construct(public readonly string $stage, string $message, public readonly bool $retryable = true, ?\Throwable $prev = null)
    { parent::__construct($message, 0, $prev); }

    public static function extractionFailed(string $r, ?\Throwable $p = null): self { return new self('extraction', "Extraction failed: {$r}", true, $p); }
    public static function validationFailed(string $r): self { return new self('validation', "Validation failed: {$r}", false); }
    public static function chunkingFailed(string $r, ?\Throwable $p = null): self { return new self('chunking', "Chunking failed: {$r}", true, $p); }
    public static function embeddingFailed(string $r, ?\Throwable $p = null): self { return new self('embedding', "Embedding failed: {$r}", true, $p); }
    public static function storageFailed(string $r, ?\Throwable $p = null): self { return new self('storage', "Storage failed: {$r}", true, $p); }
}