<?php

namespace BehindSolution\LaravelQueryGate\Support;

use Illuminate\Support\Facades\Cache;

class CacheRegistry
{
    public static function register(string $name, string $key, int $ttl): void
    {
        $listKey = self::listKey($name);
        $entries = Cache::get($listKey, []);
        $now = time();

        if (is_array($entries)) {
            $entries = array_filter($entries, static function ($expires) use ($now) {
                return !is_int($expires) || $expires > $now;
            });
        } else {
            $entries = [];
        }

        $entries[$key] = $now + $ttl;

        Cache::forever($listKey, $entries);
    }

    public static function flush(string $name): void
    {
        $listKey = self::listKey($name);
        $entries = Cache::pull($listKey, []);

        if (!is_array($entries)) {
            return;
        }

        foreach (array_keys($entries) as $key) {
            Cache::forget($key);
        }
    }

    protected static function listKey(string $name): string
    {
        return 'query-gate:cache-keys:' . md5($name);
    }
}


