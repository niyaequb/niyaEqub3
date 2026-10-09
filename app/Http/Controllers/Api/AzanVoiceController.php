<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AzanVoice;
use Illuminate\Http\JsonResponse;

/**
 * The adhan voices the app offers under Prayer settings -> Adhan voice.
 *
 * Public: nothing here is private, and the Islamic Center works for members
 * who have not signed in.
 *
 * @group Islamic Center
 */
class AzanVoiceController extends Controller
{
    public function index(): JsonResponse
    {
        $voices = AzanVoice::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (AzanVoice $voice): array => [
                'id' => $voice->id,
                'name' => $voice->name,
                'muezzin' => $voice->muezzin,
                'description' => $voice->description,
                'audio_url' => $voice->audioUrl(),
                'ios_sound_url' => $voice->iosSoundUrl(),
                'duration_seconds' => $voice->duration_seconds,
                'size_bytes' => $voice->size_bytes,
                'version' => $voice->soundVersion(),
            ])
            ->filter(fn (array $voice): bool => $voice['audio_url'] !== null)
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $voices,
        ]);
    }
}
