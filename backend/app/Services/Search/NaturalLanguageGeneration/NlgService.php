<?php

namespace App\Services\Search\NaturalLanguageGeneration;

use App\Models\SystemSetting;
use App\Services\LLM\PromptDefaults;
use App\Services\LLM\LlmClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class NlgService
{
    private int $maxTokens;
    private float $temperature;

    
    private ?string $lastPrompt = null;

    public function __construct(private LlmClient $client)
    {
        $this->maxTokens = config('text_processing.llm.max_tokens');
        $this->temperature = config('text_processing.llm.temperature');
    }

    public function generateAnswer(string $question, Collection $contextChunks, array $conversationHistory = []): string
    {
        if ($contextChunks->isEmpty()) {
            return "I don't have enough information to answer your question. Please try rephrasing or asking about something else.";
        }

        $context = $contextChunks->map(fn($chunk, $i) => 
            sprintf("[Source %d: %s]\n%s", $i + 1, $chunk->document->filename ?? 'Unknown', $chunk->content)
        )->join("\n\n");

        $prompt = $this->buildPrompt($question, $context);
        $messages = $this->buildMessages($prompt, $conversationHistory);

        try {
            $response = $this->client->chat(
                $messages,
                $this->maxTokens,
                $this->temperature,
            );

            return trim($response['choices'][0]['message']['content']
                ?? throw new \Exception('Invalid response format'));
        } catch (\Exception $e) {
            Log::error('LLM API call failed', [
                'provider' => $this->client->getProvider(),
                'model' => $this->client->getModel(),
                'error' => $e->getMessage(),
            ]);
            return "I'm sorry, I encountered an error while generating an answer. Please try again later.";
        }
    }

    /**
     * Build the full prompt by resolving the admin-editable template
     * and interpolating context + question placeholders.
     */
    private function buildPrompt(string $question, string $context): string
    {
        $template = SystemSetting::getValue(
            PromptDefaults::NLG_TEMPLATE_KEY,
            PromptDefaults::nlgTemplate(),
        );

        $this->lastPrompt = str_replace(
            ['{{context}}', '{{question}}'],
            [$context, $question],
            $template,
        );

        return $this->lastPrompt;
    }

    public function getLastPrompt(): ?string
    {
        return $this->lastPrompt;
    }

    public function getModel(): string
    {
        return $this->client->getModel();
    }

    public function getProvider(): string
    {
        return $this->client->getProvider();
    }

    /**
     * Stream an answer token-by-token for the given question and context.
     * Yields each content fragment as it arrives from the LLM.
     *
     * @return \Generator<int, string, void, string> Yields tokens; return value is the full answer
     */
    public function streamAnswer(string $question, Collection $contextChunks, array $conversationHistory = []): \Generator
    {
        if ($contextChunks->isEmpty()) {
            $fallback = "I don't have enough information to answer your question. Please try rephrasing or asking about something else.";
            yield $fallback;
            return $fallback;
        }

        $context = $contextChunks->map(fn($chunk, $i) =>
            sprintf("[Source %d: %s]\n%s", $i + 1, $chunk->document->filename ?? ($chunk->document['filename'] ?? 'Unknown'), $chunk->content ?? $chunk->content)
        )->join("\n\n");

        $prompt = $this->buildPrompt($question, $context);
        $messages = $this->buildMessages($prompt, $conversationHistory);

        $fullContent = '';

        try {
            foreach ($this->client->streamChat(
                $messages,
                $this->maxTokens,
                $this->temperature,
            ) as $token) {
                $fullContent .= $token;
                yield $token;
            }
        } catch (\Exception $e) {
            Log::error('LLM streaming failed', [
                'provider' => $this->client->getProvider(),
                'model'    => $this->client->getModel(),
                'error'    => $e->getMessage(),
            ]);

            if ($fullContent === '') {
                $errorMsg = "I'm sorry, I encountered an error while generating an answer. Please try again later.";
                yield $errorMsg;
                return $errorMsg;
            }
        }

        return trim($fullContent);
    }

    /**
     * Generate a contextual clarification message when a user's query is too vague.
     * Uses conversation history to offer relevant suggestions instead of generic advice.
     */
    public function generateClarification(string $vagueQuery, array $conversationHistory = []): string
    {
        $prompt = $this->buildClarificationPrompt($vagueQuery, $conversationHistory);

        try {
            $response = $this->client->chat(
                [['role' => 'user', 'content' => $prompt]],
                200,
                $this->temperature,
            );

            return trim($response['choices'][0]['message']['content']
                ?? throw new \Exception('Invalid response format'));
        } catch (\Exception $e) {
            Log::error('Clarification generation failed', [
                'provider' => $this->client->getProvider(),
                'model'    => $this->client->getModel(),
                'error'    => $e->getMessage(),
            ]);

            return "I'm not sure what you're looking for. Could you provide more details about what information you need?";
        }
    }

    private function buildClarificationPrompt(string $query, array $conversationHistory): string
    {
        $historyContext = '';

        if (!empty($conversationHistory)) {
            $recent = array_slice($conversationHistory, -3);
            $lines = array_map(
                fn($turn) => sprintf('- User asked: "%s" (intent: %s)', $turn['query'], $turn['intent']),
                $recent,
            );
            $historyContext = "Recent conversation:\n" . implode("\n", $lines) . "\n\n";
        }

        return <<<PROMPT
        The user submitted a vague or unclear search query: "{$query}"

        {$historyContext}Generate a brief, helpful clarification message asking the user to be more specific. If there is conversation history, reference it to make your suggestions contextually relevant. Include 2-3 specific example queries they could try.

        Keep the response concise, friendly, and under 100 words.
        PROMPT;
    }
    
    private function buildMessages(string $currentPrompt, array $conversationHistory = []): array
    {
        if (empty($conversationHistory)) {
            return [['role' => 'user', 'content' => $currentPrompt]];
        }

        $messages = [];
        foreach (array_slice($conversationHistory, -3) as $turn) {
            $messages[] = ['role' => 'user', 'content' => $turn['query']];

            if (!empty($turn['answer'])) {
                $messages[] = ['role' => 'assistant', 'content' => $turn['answer']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $currentPrompt];

        return $messages;
    }
}