<?php

namespace App\Models;

use App\Services\AzanVoiceClipper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * One adhan voice offered in the app. See the migration for the two files.
 */
class AzanVoice extends Model
{
    protected $fillable = [
        'name',
        'muezzin',
        'description',
        'audio_path',
        'ios_sound_path',
        'duration_seconds',
        'size_bytes',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'duration_seconds' => 'integer',
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AzanVoice $voice): void {
            if (! $voice->isDirty('audio_path')) {
                return;
            }

            // A new recording invalidates everything worked out from the old
            // one, and the old files are no longer referenced by anything.
            $disk = Storage::disk('public');
            $old = $voice->getOriginal('audio_path');

            $voice->size_bytes = $voice->audio_path && $disk->exists($voice->audio_path)
                ? $disk->size($voice->audio_path)
                : null;

            if (! $voice->isDirty('duration_seconds')) {
                $voice->duration_seconds = null;
            }

            // The iPhone clip made from the old recording, unless the admin
            // uploaded a clip of their own in this same save.
            $oldClip = $old ? 'azan-voices/ios/'.pathinfo($old, PATHINFO_FILENAME).'.caf' : null;
            if (! $voice->isDirty('ios_sound_path') && $oldClip !== null && $voice->ios_sound_path === $oldClip) {
                $disk->delete($oldClip);
                $voice->ios_sound_path = null;
            }

            if ($old && $old !== $voice->audio_path && $disk->exists($old)) {
                $disk->delete($old);
            }
        });

        static::saved(function (AzanVoice $voice): void {
            if ($voice->wasRecentlyCreated || $voice->wasChanged(['audio_path', 'ios_sound_path'])) {
                app(AzanVoiceClipper::class)->prepare($voice);
            }
        });

        static::deleted(function (AzanVoice $voice): void {
            $disk = Storage::disk('public');
            foreach ([$voice->audio_path, $voice->ios_sound_path] as $path) {
                if ($path && $disk->exists($path)) {
                    $disk->delete($path);
                }
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function audioUrl(): ?string
    {
        return $this->audio_path ? Storage::disk('public')->url($this->audio_path) : null;
    }

    public function iosSoundUrl(): ?string
    {
        return $this->ios_sound_path ? Storage::disk('public')->url($this->ios_sound_path) : null;
    }

    /**
     * Changes when either file is replaced, and only then.
     *
     * The app makes it part of the sound's name: Android fixes a notification
     * channel's sound when the channel is created, and iOS caches sounds by
     * file name, so a replaced recording has to arrive under a new name.
     */
    public function soundVersion(): int
    {
        return crc32($this->audio_path.'|'.$this->ios_sound_path) % 100000000;
    }
}
