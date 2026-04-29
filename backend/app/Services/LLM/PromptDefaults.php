<?php

namespace App\Services\LLM;

/**
 * Canonical default prompt templates for all LLM-powered services.
 *
 * Centralised here so the same defaults are used for initial seeding,
 * runtime fallbacks, and admin "Reset to Default" actions.
 */
final class PromptDefaults
{
    /**
     * System-setting key for the NLG (answer generation) prompt template.
     * Supports placeholders: {{context}}, {{question}}
     */
    public const NLG_TEMPLATE_KEY = 'prompt_nlg_template';

    /**
     * System-setting key for the intent classifier system message.
     */
    public const INTENT_SYSTEM_KEY = 'prompt_intent_system';

    /**
     * Placeholders the NLG template may use.
     */
    public const NLG_PLACEHOLDERS = ['{{context}}', '{{question}}'];

    public static function nlgTemplate(): string
    {
        return <<<'PROMPT'
    You are a helpful assistant. Use the following provided context to answer the user's question. If the answer cannot be found in the context, state that you don't know.

    IMPORTANT: When you use information from a source, cite it inline using ONLY the source number in square brackets, e.g. [1], [2]. Do NOT write "Source 1" or "[Source 1]" — use ONLY the numeric form like [1]. Place the citation immediately after the claim it supports. You may cite multiple sources for a single claim, e.g. [1][3]. Only cite sources you actually used.

    Context:
    ---
    {{context}}
    ---

    Question: {{question}}

    Answer:
    PROMPT;
    }

    public static function intentSystem(): string
    {
        return 'You are a precise intent classifier. Output only valid JSON.';
    }

    /**
     * All managed prompt keys with their defaults and metadata.
     *
     * @return array<string, array{default: string, label: string, description: string, placeholders: string[]}>
     */
    public static function all(): array
    {
        return [
            self::NLG_TEMPLATE_KEY => [
                'default'      => self::nlgTemplate(),
                'label'        => 'Answer Generation Prompt',
                'description'  => 'Full prompt template sent to the LLM for generating answers. Use {{context}} and {{question}} as placeholders.',
                'placeholders' => self::NLG_PLACEHOLDERS,
            ],
            self::INTENT_SYSTEM_KEY => [
                'default'      => self::intentSystem(),
                'label'        => 'Intent Classifier System Message',
                'description'  => 'System message prepended to every intent classification request. Keep it short and directive.',
                'placeholders' => [],
            ],
        ];
    }
}