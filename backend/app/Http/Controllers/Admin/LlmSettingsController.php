<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\LLM\OllamaModelManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LlmSettingsController extends Controller
{
    private const ALLOWED_MODES = ['local', 'cloud'];

    public function __construct(private OllamaModelManager $ollamaManager) {}

    public function show(): JsonResponse
    {
        $mode = SystemSetting::getValue('llm_mode', 'local');

        return response()->json([
            'mode' => $mode,
            'profiles' => [
                'local' => $this->describeProfile('local'),
                'cloud' => $this->describeProfile('cloud'),
            ],
            'ollama' => $this->getOllamaStatus(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'mode' => 'required|string|in:' . implode(',', self::ALLOWED_MODES),
        ]);

        $newMode = $request->input('mode');
        SystemSetting::setValue('llm_mode', $newMode);

        return response()->json([
            'mode' => $newMode,
            'message' => "LLM mode switched to {$newMode}.",
            'active_profile' => $this->describeProfile($newMode),
        ]);
    }

    /**
     * Get the current status of all configured Ollama models.
     */
    public function ollamaStatus(): JsonResponse
    {
        return response()->json($this->getOllamaStatus());
    }

    /**
     * Load a configured model into Ollama's memory.
     */
    public function warmUpModel(Request $request): JsonResponse
    {
        $request->validate(['model' => 'required|string']);

        $model = $request->input('model');
        $configured = $this->ollamaManager->getConfiguredModels();

        if (!in_array($model, $configured)) {
            return response()->json([
                'error' => "Model '{$model}' is not in the local profile configuration.",
                'configured_models' => $configured,
            ], 422);
        }

        if (!$this->ollamaManager->isAvailable()) {
            return response()->json(['error' => 'Ollama server is not reachable.'], 503);
        }

        $success = $this->ollamaManager->warmUp($model);

        return $success
            ? response()->json(['message' => "Model '{$model}' loaded into memory.", 'model' => $model])
            : response()->json(['error' => "Failed to warm up model '{$model}'."], 500);
    }

    /**
     * Unload a model from Ollama's memory.
     */
    public function unloadModel(Request $request): JsonResponse
    {
        $request->validate(['model' => 'required|string']);

        $model = $request->input('model');

        if (!$this->ollamaManager->isAvailable()) {
            return response()->json(['error' => 'Ollama server is not reachable.'], 503);
        }

        $success = $this->ollamaManager->unload($model);

        return $success
            ? response()->json(['message' => "Model '{$model}' unloaded from memory.", 'model' => $model])
            : response()->json(['error' => "Failed to unload model '{$model}'."], 500);
    }

    private function describeProfile(string $mode): array
    {
        return [
            'llm' => [
                'provider' => config("text_processing.llm.profiles.{$mode}.provider"),
                'model'    => config("text_processing.llm.profiles.{$mode}.model"),
                'timeout'  => config("text_processing.llm.profiles.{$mode}.timeout"),
            ],
            'intent_classification' => [
                'provider' => config("text_processing.intent_classification.profiles.{$mode}.provider"),
                'model'    => config("text_processing.intent_classification.profiles.{$mode}.model"),
                'timeout'  => config("text_processing.intent_classification.profiles.{$mode}.timeout"),
            ],
        ];
    }

    private function getOllamaStatus(): array
    {
        $available = $this->ollamaManager->isAvailable();
        $configuredModels = $this->ollamaManager->getConfiguredModels();
        $loadedModels = $available ? $this->ollamaManager->getLoadedModels() : [];

        $models = array_map(fn (string $name) => [
            'name' => $name,
            'loaded' => isset($loadedModels[$name]),
            'size_vram' => $loadedModels[$name]['size_vram'] ?? null,
            'expires_at' => $loadedModels[$name]['expires_at'] ?? null,
        ], $configuredModels);

        return [
            'available' => $available,
            'models' => $models,
        ];
    }
}