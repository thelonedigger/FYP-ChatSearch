<?php

namespace App\Services\Search\NaturalLanguageUnderstanding\Contracts;

/**
 * Contract for intent classification strategies.
 */
interface IntentClassifierInterface
{
    /**
     * Classify user intent from query text.
     */
    public function classify(string $query, bool $hasContext, array $conversationHistory = []): array;
    
    /**
     * Get all supported intent types.
     */
    public function getSupportedIntents(): array;
}