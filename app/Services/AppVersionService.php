<?php

namespace App\Services;

use App\Models\GlobalSetting;

/**
 * Tells the app whether a newer build is on the store, and whether it may
 * carry on running without it.
 *
 * WHY THE COMPARISON HAPPENS HERE AND NOT IN THE APP
 *
 * The decision is the server's on purpose. A client that decides for itself
 * can only ever apply the rule it shipped with, which means the one build you
 * most need to reach — the broken one already in people's hands — is the one
 * that cannot be told anything new. Sending a plain verdict instead lets an
 * admin force an upgrade on a version released months ago.
 *
 * VERSIONS
 *
 * Compared as dotted numbers, "1.0.10" above "1.0.9", missing parts read as
 * zero so "1.1" and "1.1.0" are the same release. A "+build" suffix is only
 * looked at when the dotted parts are equal, which is what makes a hotfix
 * shipped as 1.0.1+23 register as newer than 1.0.1+22.
 */
class AppVersionService
{
    /** Everything an app needs to decide what to show, in one payload. */
    public function statusFor(string $platform, ?string $currentVersion): array
    {
        $platform = in_array($platform, ['ios', 'android'], true) ? $platform : 'android';
        $settings = GlobalSetting::query()->pluck('value', 'key');

        $latest = trim((string) $settings->get("{$platform}_latest_version", ''));
        $minimum = trim((string) $settings->get("{$platform}_min_version", ''));
        $storeUrl = trim((string) $settings->get("{$platform}_store_url", ''));
        $notes = trim((string) $settings->get('update_release_notes', ''));

        $current = trim((string) $currentVersion);

        // No version configured means the feature is simply off. Returning
        // "no update" rather than erroring keeps a fresh install from nagging
        // before an admin has filled anything in.
        $hasLatest = $latest !== '' && $current !== '';

        $updateAvailable = $hasLatest && $this->compare($current, $latest) < 0;

        // A forced update only makes sense when there is somewhere to send
        // people. Without a store URL the app would show a blocking screen
        // whose only button does nothing.
        $forceUpdate = $updateAvailable
            && $minimum !== ''
            && $storeUrl !== ''
            && $this->compare($current, $minimum) < 0;

        return [
            'platform' => $platform,
            'current_version' => $current !== '' ? $current : null,
            'latest_version' => $latest !== '' ? $latest : null,
            'minimum_version' => $minimum !== '' ? $minimum : null,
            'update_available' => $updateAvailable,
            'force_update' => $forceUpdate,
            'store_url' => $storeUrl !== '' ? $storeUrl : null,
            // One note per line in the admin box, sent as a list so the app
            // can render bullets without parsing anything.
            'release_notes' => $this->splitNotes($notes),
        ];
    }

    /**
     * -1 when $a is older than $b, 0 when the same, 1 when newer.
     */
    public function compare(string $a, string $b): int
    {
        [$aVersion, $aBuild] = $this->split($a);
        [$bVersion, $bBuild] = $this->split($b);

        $length = max(count($aVersion), count($bVersion));

        for ($i = 0; $i < $length; $i++) {
            $left = $aVersion[$i] ?? 0;
            $right = $bVersion[$i] ?? 0;

            if ($left !== $right) {
                return $left < $right ? -1 : 1;
            }
        }

        // Dotted parts identical: the build number breaks the tie, so a
        // rebuild of the same version still counts as newer.
        if ($aBuild !== $bBuild) {
            return $aBuild < $bBuild ? -1 : 1;
        }

        return 0;
    }

    /**
     * "1.0.1+22" becomes [[1, 0, 1], 22]. Anything unparseable degrades to
     * zeros rather than throwing — a malformed value typed into the admin
     * panel must not take the endpoint down.
     *
     * @return array{0: array<int, int>, 1: int}
     */
    protected function split(string $raw): array
    {
        $raw = trim($raw);
        $parts = explode('+', $raw, 2);

        $version = array_map(
            static fn ($piece): int => (int) preg_replace('/\D+/', '', $piece),
            explode('.', $parts[0])
        );

        $build = isset($parts[1]) ? (int) preg_replace('/\D+/', '', $parts[1]) : 0;

        return [$version, $build];
    }

    /** @return array<int, string> */
    protected function splitNotes(string $notes): array
    {
        if ($notes === '') {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $notes))
            ->map(fn ($line): string => trim(ltrim(trim($line), '-•*')))
            ->filter(fn (string $line): bool => $line !== '')
            ->values()
            ->all();
    }
}
