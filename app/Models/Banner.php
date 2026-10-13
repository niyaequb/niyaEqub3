<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Banner extends Model
{
    /**
     * global_settings key holding how long each banner stays on screen, in
     * seconds. Kept in the settings table rather than a column on banners
     * because it describes the carousel as a whole, not any one banner.
     */
    public const SLIDE_DURATION_KEY = 'banner_slide_duration';

    public const DEFAULT_SLIDE_DURATION = 5;

    public const MIN_SLIDE_DURATION = 2;

    public const MAX_SLIDE_DURATION = 60;

    protected $fillable = [
        'image_path',
        'title',
        'subtitle',
        'link',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    /**
     * Seconds each banner is shown before the carousel slides on.
     *
     * Always returns a usable number: a missing, blank or out-of-range stored
     * value falls back to the default or is clamped, so a bad edit in the
     * database can never make the app flip banners every frame or never.
     */
    public static function slideDuration(): int
    {
        $stored = GlobalSetting::where('key', self::SLIDE_DURATION_KEY)->value('value');

        if (! is_numeric($stored)) {
            return self::DEFAULT_SLIDE_DURATION;
        }

        return max(
            self::MIN_SLIDE_DURATION,
            min(self::MAX_SLIDE_DURATION, (int) $stored)
        );
    }

    public static function setSlideDuration(int $seconds): void
    {
        $seconds = max(
            self::MIN_SLIDE_DURATION,
            min(self::MAX_SLIDE_DURATION, $seconds)
        );

        GlobalSetting::updateOrCreate(
            ['key' => self::SLIDE_DURATION_KEY],
            ['value' => (string) $seconds, 'group' => 'promotion']
        );

        // SettingsService caches reads for an hour; drop it so anything that
        // reads this key through that service sees the change straight away.
        Cache::forget('settings.'.self::SLIDE_DURATION_KEY);
    }
}
