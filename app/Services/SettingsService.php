<?php

namespace App\Services;

use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    /**
     * Get a setting value by key.
     */
    public function get(string $key, $default = null)
    {
        return Cache::remember("settings.{$key}", 3600, function () use ($key, $default) {
            $setting = GlobalSetting::where('key', $key)->first();
            return $setting ? $setting->value : $default;
        });
    }

    /**
     * Set a setting value by key.
     */
    public function set(string $key, $value): bool
    {
        $setting = GlobalSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        Cache::forget("settings.{$key}");

        return $setting->exists;
    }

    /**
     * Get multiple settings.
     */
    public function getMultiple(array $keys, array $defaults = []): array
    {
        $settings = [];
        foreach ($keys as $key) {
            $settings[$key] = $this->get($key, $defaults[$key] ?? null);
        }
        return $settings;
    }

    /**
     * Set multiple settings.
     */
    public function setMultiple(array $values): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
        return true;
    }
}
