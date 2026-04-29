<?php

namespace App\Services\LLM;

use Illuminate\Support\Facades\Http;

/**
 * Provider-agnostic LLM client for any OpenAI-compatible chat completions API.
 * Supports OpenAI, Ollama, and any other service exposing the same contract.
 */
class LlmClient
{
    public function __construct(
        private string $provider,
        private string $model,
        private string $baseUrl,
        private ?string $apiKey = null,
        private int $timeout = 30,
    ) {}

    /**
     * Build an instance from a config subsection (e.g. 'text_processing.llm').
     */
    public static function fromConfig(string $configKey): self
    {
        $config = config($configKey);

        return new self(
            provider: $config['provider'],
            model: $config['model'],
            baseUrl: $config['base_url'] ?? self::resolveBaseUrl($config['provider']),
            apiKey: $config['api_key'] ?? null,
            timeout: $config['timeout'] ?? 30,
        );
    }

    /**
     * Build an instance from a specific profile within a config section.
     *
     * @param  string  $configKey  Root config key (e.g. 'text_processing.llm')
     * @param  string  $mode       Profile name ('local' or 'cloud')
     */
    public static function fromProfile(string $configKey, string $mode): self
    {
        $profile = config("{$configKey}.profiles.{$mode}");

        if (!$profile) {
            throw new \InvalidArgumentException(
                "LLM profile '{$mode}' not found under '{$configKey}.profiles'."
            );
        }

        return new self(
            provider: $profile['provider'],
            model: $profile['model'],
            baseUrl: $profile['base_url'] ?? self::resolveBaseUrl($profile['provider']),
            apiKey: $profile['api_key'] ?? null,
            timeout: $profile['timeout'] ?? 30,
        );
    }

    /**
     * Send a chat completion request.
     *
     * @param  array  $messages   OpenAI-format message array
     * @param  int    $maxTokens  Maximum tokens to generate
     * @param  float  $temperature Sampling temperature
     * @param  array  $options    Additional payload keys (e.g. response_format)
     * @return array  Decoded JSON response
     */
    public function chat(array $messages, int $maxTokens, float $temperature, array $options = []): array
    {
        $payload = array_merge([
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
            $this->maxTokensKey() => $maxTokens,
        ], $options);

        $request = Http::timeout($this->timeout)
            ->withHeaders(['Content-Type' => 'application/json']);

        if ($this->apiKey) {
            $request = $request->withHeaders(['Authorization' => "Bearer {$this->apiKey}"]);
        }

        $response = $request->post("{$this->baseUrl}/chat/completions", $payload);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "LLM request failed [{$this->provider}/{$this->model}]: {$response->body()}"
            );
        }

        return $response->json();
    }

    /**
     * Stream a chat completion request, yielding content tokens as they arrive.
     * Works with any OpenAI-compatible SSE endpoint (OpenAI, Ollama, etc.).
     *
     * @param  array  $messages    OpenAI-format message array
     * @param  int    $maxTokens   Maximum tokens to generate
     * @param  float  $temperature Sampling temperature
     * @param  array  $options     Additional payload keys
     * @return \Generator<int, string, void, void> Yields content token strings
     */
    public function streamChat(array $messages, int $maxTokens, float $temperature, array $options = []): \Generator
    {
        $payload = array_merge([
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'stream'      => true,
            $this->maxTokensKey() => $maxTokens,
        ], $options);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'text/event-stream',
        ];

        if ($this->apiKey) {
            $headers['Authorization'] = "Bearer {$this->apiKey}";
        }

        $response = Http::timeout($this->timeout)
            ->withHeaders($headers)
            ->withOptions(['stream' => true])
            ->post("{$this->baseUrl}/chat/completions", $payload);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "LLM stream request failed [{$this->provider}/{$this->model}]: {$response->body()}"
            );
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '' || $chunk === false) {
                break;
            }

            $buffer .= $chunk;
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $newlinePos), "\r");
                $buffer = substr($buffer, $newlinePos + 1);

                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = substr($line, 6);

                if (trim($data) === '[DONE]') {
                    return;
                }

                $json = json_decode($data, true);
                $content = $json['choices'][0]['delta']['content'] ?? '';

                if ($content !== '') {
                    yield $content;
                }
            }
        }
    }

    /**
     * OpenAI's newer models expect 'max_completion_tokens'; Ollama and others use 'max_tokens'.
     */
    private function maxTokensKey(): string
    {
        return match ($this->provider) {
            'openai' => 'max_completion_tokens',
            default  => 'max_tokens',
        };
    }

    /**
     * Resolve the base URL for known providers so each config block
     * doesn't have to spell it out unless it wants to override.
     */
    private static function resolveBaseUrl(string $provider): string
    {
        return match ($provider) {
            'openai' => 'https://api.openai.com/v1',
            'ollama' => rtrim(config('text_processing.ollama.base_url', 'http://ollama:11434'), '/') . '/v1',
            default  => throw new \InvalidArgumentException(
                "Unknown LLM provider '{$provider}'. Set a 'base_url' explicitly in your config."
            ),
        };
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }
}