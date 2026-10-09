<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adhan voices members can download in the app (Prayer settings -> Adhan
 * voice) and choose for their prayer-time notifications.
 *
 * Two files per voice, because the two phone platforms want different things:
 *
 *   audio_path      the full recording. The app's preview plays it, and
 *                   Android uses it as the notification sound.
 *   ios_sound_path  a clip of at most 30 seconds. iOS refuses longer
 *                   notification sounds (and plays its default chime
 *                   instead). Made automatically from the recording when
 *                   ffmpeg is on the server; an admin can also upload one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('azan_voices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('muezzin', 120)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('audio_path');
            $table->string('ios_sound_path')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('azan_voices');
    }
};
