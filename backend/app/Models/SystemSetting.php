<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Simple key-value store for system-wide settings.
 * Reads are cached and automatically busted on write.
 */
class SystemSetting extends Model
{
    protected $fillable = ['key', 'value'];

    private const CACHE_PREFIX = 'system_setting:';
    private const CACHE_TTL = 3600; // 1 hour — bust on write anyway

    /**
     * Retrieve a setting value with an in-memory cache layer.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            self::CACHE_PREFIX . $key,
            self::CACHE_TTL,
            fn () => static::where('key', $key)->value('value') ?? $default,
        ) ?? $default;
    }

    /**
     * Persist a setting value and bust the cache immediately.
     */
    public static function setValue(string $key, string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget(self::CACHE_PREFIX . $key);
    }
}