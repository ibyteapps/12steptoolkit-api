<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Things the console can switch without a deploy. Stored as JSON in `settings`,
 * cached, with the code's own default when nothing has been saved.
 */
final class Settings
{
    private const CACHE_KEY = 'settings.all';

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    private static function all(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, 300, fn () => Setting::query()->get()
                ->mapWithKeys(fn (Setting $s) => [$s->key => $s->value['v'] ?? null])->all());
        } catch (Throwable) {
            return []; // before the migration has run: the defaults apply
        }
    }
}
