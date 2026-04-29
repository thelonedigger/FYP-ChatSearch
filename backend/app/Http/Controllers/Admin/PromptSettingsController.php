<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\LLM\PromptDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromptSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $prompts = [];

        foreach (PromptDefaults::all() as $key => $meta) {
            $prompts[] = [
                'key'          => $key,
                'label'        => $meta['label'],
                'description'  => $meta['description'],
                'placeholders' => $meta['placeholders'],
                'value'        => SystemSetting::getValue($key, $meta['default']),
                'is_default'   => SystemSetting::getValue($key, $meta['default']) === $meta['default'],
            ];
        }

        return response()->json(['prompts' => $prompts]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'key'   => 'required|string|in:' . implode(',', array_keys(PromptDefaults::all())),
            'value' => 'required|string|min:1|max:10000',
        ]);

        $key  = $request->input('key');
        $value = $request->input('value');
        $meta = PromptDefaults::all()[$key];
        foreach ($meta['placeholders'] as $placeholder) {
            if (!str_contains($value, $placeholder)) {
                return response()->json([
                    'error'   => "Missing required placeholder: {$placeholder}",
                    'message' => "The prompt must contain all required placeholders: " . implode(', ', $meta['placeholders']),
                ], 422);
            }
        }

        SystemSetting::setValue($key, $value);

        return response()->json([
            'key'        => $key,
            'value'      => $value,
            'is_default' => $value === $meta['default'],
            'message'    => "{$meta['label']} updated successfully.",
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'key' => 'required|string|in:' . implode(',', array_keys(PromptDefaults::all())),
        ]);

        $key  = $request->input('key');
        $meta = PromptDefaults::all()[$key];
        $default = $meta['default'];

        SystemSetting::setValue($key, $default);

        return response()->json([
            'key'        => $key,
            'value'      => $default,
            'is_default' => true,
            'message'    => "{$meta['label']} reset to default.",
        ]);
    }
}