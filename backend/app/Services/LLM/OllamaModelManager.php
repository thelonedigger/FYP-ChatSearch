<?php

namespace App\Services\LLM;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Manages Ollama model lifecycle: status checks, warm-up, and unloading.
 * Communicates with Ollama's native API (not the OpenAI-compatible layer).
 */
class OllamaModelManager
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('text_processing.ollama.base_url', 'http://ollama:11434'), '/');
    }

    /**
     * Get all models currently loaded in Ollama's memory.
     *
     * @return array<string, array{size_vram: int, expires_at: string}>
     */
    public function getLoadedModels(): array
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/ps");

            if (!$response->successful()) {
                return [];
            }

            $loaded = [];
            foreach ($response->json('models', []) as $model) {
                $loaded[$model['name']] = [
                    'size_vram' => $model['size_vram'] ?? $model['size'] ?? 0,
                    'expires_at' => $model['expires_at'] ?? null,
                ];
            }

            return $loaded;
        } catch (\Exception $e) {
            Log::warning('Failed to query Ollama running models', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Check whether the Ollama server is reachable.
     */
    public function isAvailable(): bool
    {
        try {
            return Http::timeout(3)->get("{$this->baseUrl}/api/tags")->successful();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Load a model into memory without performing inference.
     * This can take a while for large models — use a generous timeout.
     */
    public function warmUp(string $model): bool
    {
        try {
            $response = Http::timeout(300)->post("{$this->baseUrl}/api/generate", [
                'model' => $model,
                'prompt' => '',
                'keep_alive' => -1,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Failed to warm up Ollama model', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Unload a model from memory, freeing VRAM/RAM.
     */
    public function unload(string $model): bool
    {
        try {
            $response = Http::timeout(30)->post("{$this->baseUrl}/api/generate", [
                'model' => $model,
                'prompt' => '',
                'keep_alive' => 0,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Failed to unload Ollama model', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Collect the distinct model names used across all local profiles.
     *
     * @return string[]
     */
    public function getConfiguredModels(): array
    {
        $models = collect([
            config('text_processing.llm.profiles.local.model'),
            config('text_processing.intent_classification.profiles.local.model'),
        ]);

        if (config('text_processing.embeddings.provider') === 'ollama') {
            $models->push(config('text_processing.embeddings.providers.ollama.model'));
        }

        if (config('text_processing.reranker.provider') === 'ollama') {
            $models->push(config('text_processing.reranker.providers.ollama.model'));
        }

        return $models->filter()->unique()->values()->all();
    }
}