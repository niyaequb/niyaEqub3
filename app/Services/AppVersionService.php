<?php

namespace App\Services;

use App\Models\GlobalSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Tells the app whether a newer build is on the store, and whether it may
 * carry on running without it.
 *
 * WHERE THE VERSION COMES FROM
 *
 * Two places, and the higher of the two wins:
 *
 *   1. Settings → App Version in the admin panel. An explicit override, and
 *      the only way to push a version the store has not finished rolling out.
 *   2. The store itself, read live by StoreLookupService and cached.
 *
 * The second one is the fix for the original complaint. The panel was the only
 * source, so a release that went live on Play and nowhere else was invisible
 * to the app and nobody was ever prompted. Now publishing is enough, and the
 * panel is there for when you need to say something the store cannot.
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
 * shipped as 1.0.1+26 register as newer than 1.0.1+25. Store versions never
 * carry a build suffix, so a running "1.0.1+25" against a published "1.0.1"
 * is a tie rather than a downgrade — which is right, since neither store has
 * any idea what build number went into the release it is serving.
 */
class AppVersionService
{
    public function __construct(protected StoreLookupService $store) {}

    /** Everything an app needs to decide what to show, in one payload. */
    public function statusFor(string $platform, ?string $currentVersion): array
    {
        $platform = in_array($platform, ['ios', 'android'], true) ? $platform : 'android';
        $settings = GlobalSetting::query()->pluck('value', 'key');

        $configuredLatest = trim((string) $settings->get("{$platform}_latest_version", ''));
        $minimum = trim((string) $settings->get("{$platform}_min_version", ''));
        $configuredStoreUrl = trim((string) $settings->get("{$platform}_store_url", ''));
        $notes = trim((string) $settings->get('update_release_notes', ''));

        $current = trim((string) $currentVersion);

        $packageId = $this->packageIdFor($platform, $settings, $configuredStoreUrl);
        $storeRelease = $this->autoLookupEnabled($settings)
            ? $this->store->release($platform, $packageId)
            : null;

        // The higher of the two wins. An admin who publishes to Play and
        // forgets the panel is covered by the store; an admin who needs to
        // name a version the store is still rolling out is covered by the
        // panel.
        $latest = $configuredLatest;
        $storeVersion = (string) ($storeRelease['version'] ?? '');

        // A version read off a web page is only trusted when it looks like a
        // release of THIS app. Play listings are full of unrelated numbers —
        // library versions, screen sizes, a user agent in an inline script —
        // and one bad match here would put a blocking screen in front of every
        // member. "1.1" and "2.0" pass; "122.0.0.0" and "537.36" do not.
        if ($storeVersion !== '' && ! $this->isPlausibleRelease($configuredLatest ?: $current, $storeVersion)) {
            Log::warning('Ignoring implausible store version', [
                'platform' => $platform,
                'store_version' => $storeVersion,
                'reference' => $configuredLatest ?: $current,
            ]);
            $storeVersion = '';
        }

        if ($storeVersion !== '' && ($latest === '' || $this->compare($latest, $storeVersion) < 0)) {
            $latest = $storeVersion;
        }

        // A prompt has to lead somewhere. For Android the listing URL is
        // always constructible from the package id, so there is no excuse for
        // an update prompt with a dead button.
        $storeUrl = $configuredStoreUrl;
        if ($storeUrl === '') {
            $storeUrl = (string) ($storeRelease['store_url'] ?? '');
        }
        if ($storeUrl === '' && $platform === 'android') {
            $storeUrl = $this->store->playUrlFor($packageId);
        }

        // Admin-written notes are aimed at members and can be translated; the
        // store's are whatever went into the release listing. Prefer the
        // former, fall back to the latter.
        $releaseNotes = $this->splitNotes($notes);
        if ($releaseNotes === [] && $storeRelease !== null) {
            $releaseNotes = (array) $storeRelease['release_notes'];
        }

        // Nothing published anywhere means the feature is simply off.
        // Returning "no update" rather than erroring keeps a fresh install
        // from nagging before anything has been released.
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
            'release_notes' => $releaseNotes,
            // Diagnostics only. The app shows this on a long-press of the
            // App version row, which is the difference between "why is
            // nothing happening" and knowing which source answered.
            'source' => $this->describeSource($configuredLatest, $storeVersion !== '' ? $storeRelease : null),
            'package_id' => $packageId,
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
     * Guards against a mis-parsed store version.
     *
     * A genuine next release shares the reference major number or is exactly
     * one ahead. Anything further away came from somewhere else on the page.
     * With no reference at all there is nothing to check against, so the value
     * is accepted — the app applies the same guard against the build actually
     * running, which is the stricter test.
     */
    protected function isPlausibleRelease(string $reference, string $candidate): bool
    {
        $reference = trim($reference);
        if ($reference === '') {
            return true;
        }

        [$referenceParts] = $this->split($reference);
        [$candidateParts] = $this->split($candidate);

        $referenceMajor = $referenceParts[0] ?? 0;
        $candidateMajor = $candidateParts[0] ?? 0;

        return $candidateMajor >= $referenceMajor
            && $candidateMajor <= $referenceMajor + 1;
    }

    /**
     * The application id / bundle identifier to look up.
     *
     * Taken from the panel when someone has set it, otherwise lifted out of
     * the store URL, otherwise the app's own id. Three fallbacks because the
     * whole point of this feature is that it keeps working when nobody has
     * filled anything in.
     *
     * @param  Collection<string, mixed>  $settings
     */
    protected function packageIdFor(string $platform, Collection $settings, string $storeUrl): string
    {
        $key = $platform === 'ios' ? 'ios_bundle_id' : 'android_package_id';

        $configured = trim((string) $settings->get($key, ''));
        if ($configured !== '') {
            return $configured;
        }

        // A Play link carries the id in a query parameter.
        if ($platform === 'android' && $storeUrl !== ''
            && preg_match('/[?&]id=([A-Za-z0-9_.]+)/', $storeUrl, $matches)) {
            return $matches[1];
        }

        return StoreLookupService::DEFAULT_PACKAGE_ID;
    }

    /**
     * The live store lookup can be switched off from the panel by setting
     * app_version_auto_lookup to 0 — for a server with no outbound internet,
     * or while debugging a bad parse.
     *
     * @param  Collection<string, mixed>  $settings
     */
    protected function autoLookupEnabled(Collection $settings): bool
    {
        $raw = trim((string) $settings->get('app_version_auto_lookup', '1'));

        return ! in_array(strtolower($raw), ['0', 'false', 'off', 'no'], true);
    }

    /** @param  array{version: string}|null  $storeRelease */
    protected function describeSource(string $configuredLatest, ?array $storeRelease): string
    {
        return match (true) {
            $configuredLatest !== '' && $storeRelease !== null => 'panel+store',
            $configuredLatest !== '' => 'panel',
            $storeRelease !== null => 'store',
            default => 'none',
        };
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
