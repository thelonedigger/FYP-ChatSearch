<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChunkingSettingsController extends Controller
{
    private const SETTING_KEY = 'chunking_strategy';
    private const ALLOWED_STRATEGIES = ['structural', 'semantic'];

    public function show(): JsonResponse
    {
        return response()->json([
            'strategy' => SystemSetting::getValue(self::SETTING_KEY, 'structural'),
            'available_strategies' => [
                [
                    'key' => 'structural',
                    'label' => 'Structural',
                    'description' => 'Splits by paragraph boundaries with sentence-aware overlap. Fast, no extra API cost.',
                ],
                [
                    'key' => 'semantic',
                    'label' => 'Semantic',
                    'description' => 'Uses embedding similarity to detect topic shifts. Higher quality for unstructured text, but costs more embedding calls.',
                ],
            ],
            'config' => [
                'chunk_size' => config('text_processing.chunking.chunk_size'),
                'min_chunk_size' => config('text_processing.chunking.min_chunk_size'),
                'overlap_sentences' => config('text_processing.chunking.overlap_sentences'),
                'semantic_threshold' => config('text_processing.chunking.semantic_threshold'),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'strategy' => 'required|string|in:' . implode(',', self::ALLOWED_STRATEGIES),
        ]);

        $strategy = $request->input('strategy');
        SystemSetting::setValue(self::SETTING_KEY, $strategy);

        return response()->json([
            'strategy' => $strategy,
            'message' => "Chunking strategy switched to {$strategy}. New documents will use this strategy.",
        ]);
    }
}