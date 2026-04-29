<?php

namespace App\Services\Documents\Processing\Contracts;

use App\Services\Documents\Processing\DTOs\PipelineContext;

/**
 * Contract for pipeline processing stages.
 * Each stage receives context, processes it, and returns updated context.
 */
interface PipelineStageInterface
{
    /**
     * Get the unique identifier for this stage.
     */
    public function getName(): string;

    /**
     * Execute the stage processing.
     *
     * @throws \App\Services\Processing\Exceptions\PipelineStageException
     */
    public function process(PipelineContext $context): PipelineContext;

    /**
     * Check if this stage should be skipped based on context.
     */
    public function shouldSkip(PipelineContext $context): bool;

    /**
     * Rollback any changes made by this stage (if supported).
     */
    public function rollback(PipelineContext $context): void;
}