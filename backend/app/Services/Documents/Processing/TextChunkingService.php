<?php

namespace App\Services\Documents\Processing;

use App\Models\SystemSetting;
use App\Services\Documents\Processing\Contracts\ChunkingStrategyInterface;
use App\Services\Documents\Processing\Strategies\SemanticChunkingStrategy;
use App\Services\Documents\Processing\Strategies\StructuralChunkingStrategy;

/**
 * Orchestrator that resolves the active chunking strategy from system
 * settings and delegates text splitting to it.
 *
 * The active strategy is resolved on every call so runtime toggles
 * via the admin panel take effect immediately.
 */
class TextChunkingService
{
    private const SETTING_KEY = 'chunking_strategy';
    private const DEFAULT_STRATEGY = 'structural';

    public function chunkText(string $text): array
    {
        return $this->resolveStrategy()->chunk($text);
    }

    public function getActiveStrategyName(): string
    {
        return SystemSetting::getValue(self::SETTING_KEY, self::DEFAULT_STRATEGY);
    }

    private function resolveStrategy(): ChunkingStrategyInterface
    {
        $name = $this->getActiveStrategyName();

        return match ($name) {
            'semantic' => app(SemanticChunkingStrategy::class),
            default    => app(StructuralChunkingStrategy::class),
        };
    }
}