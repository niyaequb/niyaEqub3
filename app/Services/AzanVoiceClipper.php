<?php

namespace App\Services;

use App\Models\AzanVoice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Works out a voice's length and makes its iPhone clip, using ffmpeg.
 *
 * iOS plays a notification sound only if it lasts 30 seconds or less, in a
 * format such as CAF; anything else is silently replaced by the default
 * chime. Most adhans run three or four minutes, so iPhones get the opening
 * 29.5 seconds, faded out over the last two and a half.
 *
 * Optional by design. Without ffmpeg on the server (sudo apt install ffmpeg)
 * nothing fails: the voice works on Android, and an admin can upload an iPhone
 * clip by hand in the same form.
 */
class AzanVoiceClipper
{
    public function prepare(AzanVoice $voice): void
    {
        $disk = Storage::disk('public');

        if (! $voice->audio_path || ! $disk->exists($voice->audio_path)) {
            return;
        }

        $source = $disk->path($voice->audio_path);
        $updates = [];

        if ($voice->duration_seconds === null && ($seconds = $this->duration($source)) !== null) {
            $updates['duration_seconds'] = $seconds;
        }

        if (! $voice->ios_sound_path && $this->installed('ffmpeg')) {
            $relative = 'azan-voices/ios/'.pathinfo($voice->audio_path, PATHINFO_FILENAME).'.caf';
            $target = $disk->path($relative);

            if (! is_dir(dirname($target))) {
                @mkdir(dirname($target), 0775, true);
            }

            $result = Process::timeout(90)->run([
                'ffmpeg', '-y', '-loglevel', 'error',
                '-i', $source,
                '-t', '29.5',
                '-af', 'afade=t=out:st=27:d=2.5',
                '-ac', '1',
                '-ar', '22050',
                '-c:a', 'pcm_s16le',
                '-f', 'caf',
                $target,
            ]);

            if ($result->successful() && is_file($target)) {
                $updates['ios_sound_path'] = $relative;
            } else {
                Log::warning('Could not make the iPhone clip for an adhan voice', [
                    'azan_voice_id' => $voice->id,
                    'error' => trim($result->errorOutput()),
                ]);
            }
        }

        if ($updates !== []) {
            // Quietly: this runs from the model's own saved event.
            $voice->forceFill($updates)->saveQuietly();
        }
    }

    private function duration(string $file): ?int
    {
        if (! $this->installed('ffprobe')) {
            return null;
        }

        $result = Process::timeout(30)->run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $file,
        ]);

        $seconds = (float) trim($result->output());

        return $result->successful() && $seconds > 0 ? (int) round($seconds) : null;
    }

    private function installed(string $binary): bool
    {
        try {
            return Process::timeout(5)->run(['which', $binary])->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
