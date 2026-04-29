<?php

namespace App\Services\Search\NaturalLanguageUnderstanding;

use App\Models\SystemSetting;
use App\Services\LLM\PromptDefaults;
use App\Services\LLM\LlmClient;
use App\Services\Search\NaturalLanguageUnderstanding\Contracts\IntentClassifierInterface;
use Illuminate\Support\Facades\Log;

class LlmBasedIntentClassifier implements IntentClassifierInterface
{
    private int $maxTokens;
    private float $temperature;
    
    private array $intentDefinitions = [
        'new_search' => 'User asks a question or wants to search for information in the knowledge base. This includes ANY question about content, policies, procedures, or information.',
        'find_document' => 'User wants to find, locate, list, or browse documents/files — either by a specific filename or by topic/subject area (e.g. "what documents do you have about finance?").',
        'refine_search' => 'User wants to narrow, filter, expand, or refine their previous search — including scoping to a specific document or adding criteria.',
        'get_more_results' => 'User explicitly asks for MORE results from their current search.',
        'summarize_result' => 'User asks for a SUMMARY or KEY POINTS from specific results.',
        'clarify_search' => 'User query is EXTREMELY vague — single words, incomplete fragments, or completely unclear (e.g., "what?", "huh?", "info").',
    ];

    public function __construct(private LlmClient $client)
    {
        $this->maxTokens = config('text_processing.intent_classification.max_tokens');
        $this->temperature = config('text_processing.intent_classification.temperature');
    }
    
    public function classify(string $query, bool $hasContext, array $conversationHistory = []): array
    {
        Log::debug('[IntentClassifier] classify called', [
            'query'                      => $query,
            'has_context'                => $hasContext,
            'conversation_history_count' => count($conversationHistory),
        ]);

        try {
            $startTime = microtime(true);
            $prompt = $this->buildPrompt($query, $hasContext, $conversationHistory);

            Log::debug('[IntentClassifier] Prompt built', [
                'prompt_length'    => strlen($prompt),
                'contains_history' => str_contains($prompt, 'Conversation history'),
                'prompt_preview'   => mb_substr($prompt, 0, 500),
            ]);

            $response = $this->client->chat(
                [
                    ['role' => 'system', 'content' => SystemSetting::getValue(
                        PromptDefaults::INTENT_SYSTEM_KEY,
                        PromptDefaults::intentSystem(),
                    )],
                    ['role' => 'user', 'content' => $prompt],
                ],
                $this->maxTokens,
                $this->temperature,
                ['response_format' => ['type' => 'json_object']],
            );

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            $rawContent = $response['choices'][0]['message']['content'] ?? '';
            $parsed = $this->parseResponse($response);

            $intent = $parsed['intent'];
            $entities = $parsed['entities'];

            if ($intent === NluService::INTENT_CLARIFY_SEARCH && $this->looksLikeRealQuestion($query)) {
                Log::info('Intent override: clarify_search -> new_search for query', ['query' => $query]);
                $intent = NluService::INTENT_NEW_SEARCH;
                $entities = [];
            }

            return [
                'intent' => $intent,
                'entities' => $entities,
                'metadata' => [
                    'llm_response_time_ms' => $responseTime,
                    'model' => $this->client->getModel(),
                    'provider' => $this->client->getProvider(),
                    'raw_response' => $rawContent,
                    'prompt' => $prompt,
                    'usage' => $response['usage'] ?? null,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('LLM intent classification failed', ['error' => $e->getMessage()]);
            return [
                'intent' => $this->looksLikeRealQuestion($query)
                    ? NluService::INTENT_NEW_SEARCH
                    : NluService::INTENT_CLARIFY_SEARCH,
                'entities' => [],
                'metadata' => ['fallback' => true, 'error' => $e->getMessage()],
            ];
        }
    }
    
    private function buildPrompt(string $query, bool $hasContext, array $conversationHistory = []): string
    {
        $availableIntents = $hasContext 
            ? array_keys($this->intentDefinitions) 
            : ['new_search', 'find_document', 'clarify_search'];
        
        $intentsText = implode("\n", array_map(
            fn($i) => "- \"{$i}\": {$this->intentDefinitions[$i]}", 
            $availableIntents
        ));
        
        $contextNote = $hasContext 
            ? "The user HAS PREVIOUS SEARCH RESULTS in their session." 
            : "The user is STARTING A NEW conversation (no previous results).";
        
        $examples = $hasContext ? $this->getExamplesWithContext() : $this->getExamplesWithoutContext();
        $historySection = $this->formatConversationHistory($conversationHistory);
            
        return <<<PROMPT
    You are an intent classifier with entity extraction for a conversational search system. {$contextNote}

    Available intents:
    {$intentsText}

    CLASSIFICATION RULES:
    1. ANY question or information request should be "new_search" — this is the PRIMARY intent
    2. "find_document" if the user wants to find, list, or browse documents/files — by name OR by topic
    3. "clarify_search" ONLY for extremely vague queries (single words, fragments, "what?", "huh?")
    4. When in doubt between new_search and clarify_search, choose new_search

    ENTITY EXTRACTION — extract relevant entities based on the intent:
    - find_document: "filename" (specific file name mentioned) OR "topic" (subject they want documents about). Set the unused one to null.
    - refine_search: "document_scope" (document name to narrow results to, or null) and "additional_terms" (new search terms to add, or null).
    - summarize_result: "result_index" as a 1-based integer (e.g. "first"=1, "second"=2, "last"=-1). null if summarizing all results.
    - All other intents: empty object {}.

    When extracting entities, resolve references like "this topic" or "that document" using the conversation history.

    Examples:
    {$examples}
    {$historySection}
    User query: "{$query}"

    Output ONLY valid JSON: {"intent": "intent_name", "entities": {...}}
    PROMPT;
    }

    private function formatConversationHistory(array $history): string
    {
        if (empty($history)) {
            return '';
        }

        $recent = array_slice($history, -5);

        $lines = array_map(
            fn(array $turn) => sprintf('- Turn %d [%s]: "%s"', $turn['turn'], $turn['intent'], $turn['query']),
            $recent,
        );

        return "Conversation history (most recent turns):\n" . implode("\n", $lines) . "\n\n";
    }
    
    private function getExamplesWithoutContext(): string
    {
        return <<<'EXAMPLES'
Query: "What happens if I fail an exam?" -> {"intent": "new_search", "entities": {}}
Query: "Tell me about student conduct" -> {"intent": "new_search", "entities": {}}
Query: "Find the student handbook document" -> {"intent": "find_document", "entities": {"filename": "student handbook", "topic": null}}
Query: "Show me exam_policy.pdf" -> {"intent": "find_document", "entities": {"filename": "exam_policy.pdf", "topic": null}}
Query: "What documents do you have about finance?" -> {"intent": "find_document", "entities": {"filename": null, "topic": "finance"}}
Query: "List all policy documents" -> {"intent": "find_document", "entities": {"filename": null, "topic": "policy"}}
Query: "info" -> {"intent": "clarify_search", "entities": {}}
Query: "what?" -> {"intent": "clarify_search", "entities": {}}
EXAMPLES;
    }
    
    private function getExamplesWithContext(): string
    {
        return <<<'EXAMPLES'
Query: "Only show results from the handbook" -> {"intent": "refine_search", "entities": {"document_scope": "handbook", "additional_terms": null}}
Query: "Also check for information about appeals" -> {"intent": "refine_search", "entities": {"document_scope": null, "additional_terms": "appeals"}}
Query: "Narrow these down to documents about grading" -> {"intent": "refine_search", "entities": {"document_scope": null, "additional_terms": "grading"}}
Query: "Give me more results" -> {"intent": "get_more_results", "entities": {}}
Query: "Summarize the first result" -> {"intent": "summarize_result", "entities": {"result_index": 1}}
Query: "Tell me more about the third one" -> {"intent": "summarize_result", "entities": {"result_index": 3}}
Query: "Summarize the last result" -> {"intent": "summarize_result", "entities": {"result_index": -1}}
Query: "Summarize everything" -> {"intent": "summarize_result", "entities": {"result_index": null}}
Query: "What about probation?" -> {"intent": "new_search", "entities": {}}
Query: "What documents cover this topic?" -> {"intent": "find_document", "entities": {"filename": null, "topic": "this topic"}}
EXAMPLES;
    }
    
    /**
     * Check if query looks like a real question that should be searched.
     */
    private function looksLikeRealQuestion(string $query): bool
    {
        $query = trim(strtolower($query));
        
        $questionWords = ['what', 'how', 'why', 'when', 'where', 'who', 'which', 'can', 'could', 'should', 'would', 'is', 'are', 'do', 'does', 'tell', 'show', 'explain'];
        foreach ($questionWords as $word) {
            if (str_starts_with($query, $word . ' ') || str_contains($query, ' ' . $word . ' ')) {
                return true;
            }
        }
        
        if (str_ends_with($query, '?')) {
            return true;
        }
        
        if (str_word_count($query) >= 4) {
            return true;
        }
        
        if (strlen($query) > 20) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Parse the LLM JSON response into intent and entities.
     *
     * @return array{intent: string, entities: array}
     */
    private function parseResponse(array $response): array
    {
        $content = trim($response['choices'][0]['message']['content']
            ?? throw new \Exception('Invalid response format'));

        $decoded = json_decode($content, true)
            ?? throw new \Exception('Failed to parse JSON: ' . json_last_error_msg());

        $intent = $decoded['intent']
            ?? throw new \Exception('Response missing "intent" field');

        if ($intent === 'fallback' || !in_array($intent, $this->getSupportedIntents())) {
            $intent = NluService::INTENT_NEW_SEARCH;
        }
        $entities = array_filter(
            $decoded['entities'] ?? [],
            fn($value) => $value !== null,
        );

        return ['intent' => $intent, 'entities' => $entities];
    }
    
    public function getSupportedIntents(): array
    {
        return array_keys($this->intentDefinitions);
    }
}