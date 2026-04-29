<?php

namespace App\Services\Search\NaturalLanguageUnderstanding;

use App\Services\Search\NaturalLanguageUnderstanding\Contracts\IntentClassifierInterface;

class NluService
{
    const INTENT_NEW_SEARCH = 'new_search';
    const INTENT_REFINE_SEARCH = 'refine_search';
    const INTENT_SUMMARIZE_RESULT = 'summarize_result';
    const INTENT_GET_MORE_RESULTS = 'get_more_results';
    const INTENT_CLARIFY_SEARCH = 'clarify_search';
    const INTENT_FIND_DOCUMENT = 'find_document';
    
    public function __construct(private IntentClassifierInterface $intentClassifier) {}

    /**
     * Classify user intent and extract structured entities in a single LLM call.
     *
     * @return array{intent: string, entities: array, metadata: array}
     */
    public function classifyIntent(string $query, bool $hasPreviousResults = false, array $conversationHistory = []): array
    {
        return $this->intentClassifier->classify($query, $hasPreviousResults, $conversationHistory);
    }
}