<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks Google Play and the App Store what version is actually published.
 *
 * WHY THE SERVER DOES THIS AT ALL
 *
 * The version prompt used to depend entirely on an admin typing the new
 * number into Settings → App Version after every release. Nobody did, every
 * time, forever — so builds went live on Play and the app never told anyone.
 * Asking the store directly means publishing is enough, and the panel becomes
 * an override rather than a requirement.
 *
 * WHY HERE AND NOT ONLY IN THE APP
 *
 * The app does its own lookup too, as a fallback for when this endpoint is
 * unreachable. But a scraper that lives on the server can be fixed by
 * deploying; one that lives in an app can only be fixed by a release that the
 * people who need it are, by definition, not installing. So this is the copy
 * that matters, and the app's is the safety net.
 *
 * EVERY FAILURE IS SILENT AND CHEAP. A blocked outbound request, a reshuffled
 * Play page or a listing that does not exist all return null, and the caller
 * carries on with whatever the admin panel says. Results — including
 * failures — are cached so a slow store can never make app launch slow.
 */
class StoreLookupService
{
    /** Matches the applicationId in Mobile/android/app/build.gradle.kts. */
    public const DEFAULT_PACKAGE_ID = 'com.niyaet.ekub';

    /** How long a successful answer is trusted. */
    protected const SUCCESS_TTL = 21600; // 6 hours

    /**
     * How long a failure is remembered. Short enough to recover on its own,
     * long enough that an outage does not mean one outbound request per app
     * launch across the whole member base.
     */
    protected const FAILURE_TTL = 1800; // 30 minutes

    /** Seconds. Deliberately tight: this sits on the app's launch path. */
    protected const CONNECT_TIMEOUT = 4;

    protected const REQUEST_TIMEOUT = 8;

    /**
     * A version string a store could plausibly have published. Anything else
     * is a parsing accident, and acting on one is worse than doing nothing —
     * this number can put a blocking screen in front of the whole app.
     */
    protected const VERSION_SHAPE = '/^\d{1,4}(\.\d{1,5}){1,3}$/';

    /**
     * The newest published release, or null when it could not be established.
     *
     * @return array{version: string, store_url: string, release_notes: array<int, string>}|null
     */
    public function release(string $platform, ?string $packageId = null): ?array
    {
        $platform = in_array($platform, ['ios', 'android'], true) ? $platform : 'android';
        $packageId = trim((string) ($packageId ?: self::DEFAULT_PACKAGE_ID));

        if ($packageId === '') {
            return null;
        }

        $key = "store-release:{$platform}:{$packageId}";
        $cached = Cache::get($key);

        // An empty array is a remembered failure, and is deliberately distinct
        // from a cache miss.
        if (is_array($cached)) {
            return $cached === [] ? null : $cached;
        }

        try {
            $release = $platform === 'ios'
                ? $this->fetchAppStore($packageId)
                : $this->fetchPlayStore($packageId);
        } catch (Throwable $e) {
            Log::warning('Store version lookup failed', [
                'platform' => $platform,
                'package' => $packageId,
                'error' => $e->getMessage(),
            ]);
            $release = null;
        }

        Cache::put(
            $key,
            $release ?? [],
            $release ? self::SUCCESS_TTL : self::FAILURE_TTL
        );

        return $release;
    }

    /** Just the version, for callers that want nothing else. */
    public function latestVersion(string $platform, ?string $packageId = null): ?string
    {
        return $this->release($platform, $packageId)['version'] ?? null;
    }

    /** The public listing page. Always constructible from a package id. */
    public function playUrlFor(string $packageId): string
    {
        return 'https://play.google.com/store/apps/details?id='.$packageId;
    }

    /**
     * Apple publishes a documented JSON lookup endpoint, so there is nothing
     * to guess at here.
     *
     * The storefront matters: an app is listed per country, and asking the
     * wrong one returns zero results rather than an error.
     *
     * @return array{version: string, store_url: string, release_notes: array<int, string>}|null
     */
    protected function fetchAppStore(string $bundleId): ?array
    {
        foreach (['ET', 'US'] as $country) {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->acceptJson()
                ->get('https://itunes.apple.com/lookup', [
                    'bundleId' => $bundleId,
                    'country' => $country,
                    'limit' => 1,
                ]);

            if (! $response->successful()) {
                continue;
            }

            $app = $response->json('results.0');
            if (! is_array($app)) {
                continue;
            }

            $version = trim((string) ($app['version'] ?? ''));
            if (! preg_match(self::VERSION_SHAPE, $version)) {
                continue;
            }

            $storeUrl = trim((string) ($app['trackViewUrl'] ?? ''));
            if ($storeUrl === '' && ! empty($app['trackId'])) {
                $storeUrl = 'https://apps.apple.com/app/id'.$app['trackId'];
            }

            if ($storeUrl === '') {
                continue;
            }

            return [
                'version' => $version,
                'store_url' => $storeUrl,
                'release_notes' => $this->splitNotes((string) ($app['releaseNotes'] ?? '')),
            ];
        }

        return null;
    }

    /**
     * Reads the public Play listing page.
     *
     * There is no official API for this. The Play Developer API reports what
     * you uploaded, needs a service account, and is not credentials this
     * request should be carrying. So the page it is — with several patterns
     * tried in turn, because Play has reshuffled that markup before and will
     * again, and a miss must read as "could not tell" rather than "no update".
     *
     * `hl=en&gl=US` is pinned on purpose: the page is localised, and parsing a
     * layout that changes with the caller's language is a bug waiting to
     * happen.
     *
     * @return array{version: string, store_url: string, release_notes: array<int, string>}|null
     */
    protected function fetchPlayStore(string $packageId): ?array
    {
        $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::REQUEST_TIMEOUT)
            ->withHeaders([
                // Play serves a stripped page to clients that do not look like
                // a browser, and the stripped page carries no version.
                'User-Agent' => 'Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 '
                    .'(KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            // The query is written into the URL rather than passed as an
            // array: Guzzle's query option REPLACES the URI's own query
            // string, which would drop the ?id= that names the app.
            ->get($this->playUrlFor($packageId).'&hl=en&gl=US');

        if (! $response->successful()) {
            return null;
        }

        $html = $response->body();

        // A real listing is hundreds of kilobytes. Anything short is a consent
        // wall, a redirect stub or an error page.
        if (strlen($html) < 2000) {
            return null;
        }

        $version = $this->parsePlayVersion($html);

        // Genuinely common and genuinely fine: "Varies with device" listings
        // publish no version at all.
        if ($version === null) {
            return null;
        }

        return [
            'version' => $version,
            'store_url' => $this->playUrlFor($packageId),
            'release_notes' => $this->parsePlayReleaseNotes($html),
        ];
    }

    /** Newest layout first; the first version-shaped match wins. */
    protected function parsePlayVersion(string $html): ?string
    {
        $patterns = [
            // Current layout: the version sits alone in a nested array inside
            // one of the AF_initDataCallback payloads at the foot of the page.
            '/\[\[\["(\d{1,4}(?:\.\d{1,5}){1,3})"\]\]/',
            '/\[\["(\d{1,4}(?:\.\d{1,5}){1,3})"\]\]/',
            // Older server-rendered layout, still served to some clients.
            '/itemprop\s*=\s*"softwareVersion"[^>]*>\s*([^<]+)</i',
            '/Current Version.{0,160}?(\d{1,4}(?:\.\d{1,5}){1,3})/s',
            '/"version"\s*:\s*"(\d{1,4}(?:\.\d{1,5}){1,3})"/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches) && ! empty($matches[1])) {
                foreach ($matches[1] as $candidate) {
                    $candidate = trim($candidate);
                    if (preg_match(self::VERSION_SHAPE, $candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    /**
     * The "What's new" block, when the page carries one.
     *
     * Purely cosmetic — the app hides the section when this is empty — so it
     * leans towards returning nothing rather than the wrong paragraph.
     *
     * @return array<int, string>
     */
    protected function parsePlayReleaseNotes(string $html): array
    {
        if (! preg_match("/What.{0,8}s new/i", $html, $marker, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $start = $marker[0][1] + strlen($marker[0][0]);
        $window = substr($html, $start, 8000);

        if (! preg_match_all('/"((?:[^"\\\\]|\\\\.){24,1500})"/', $window, $matches)) {
            return [];
        }

        foreach ($matches[1] as $candidate) {
            $decoded = $this->decodeJsString($candidate);

            if (! $this->looksLikeProse($decoded)) {
                continue;
            }

            $notes = $this->splitNotes($decoded);
            if ($notes !== []) {
                return $notes;
            }
        }

        return [];
    }

    /**
     * Release notes arrive as one blob with newlines or <br> in it. The app
     * renders a bullet per entry, so the splitting happens once, here.
     *
     * @return array<int, string>
     */
    protected function splitNotes(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $normalised = preg_replace('/<br\s*\/?>/i', "\n", $raw) ?? $raw;
        $normalised = preg_replace('/<\/p>/i', "\n", $normalised) ?? $normalised;
        $normalised = strip_tags($normalised);
        $normalised = html_entity_decode($normalised, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return collect(preg_split('/\r\n|\r|\n/', $normalised) ?: [])
            ->map(fn ($line): string => trim(ltrim(trim($line), '-•*·')))
            ->filter(fn (string $line): bool => $line !== '')
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * True when a candidate reads like sentences a person wrote, rather than
     * a URL, a token, or a run of CSS that happened to be quoted.
     */
    protected function looksLikeProse(string $value): bool
    {
        $text = trim($value);

        if (mb_strlen($text) < 20) {
            return false;
        }

        if (! preg_match('/[A-Za-z]{3}/', $text)) {
            return false;
        }

        if (str_starts_with($text, 'http') || str_starts_with($text, '//')) {
            return false;
        }

        if (str_contains($text, '{') || str_contains($text, ';}')) {
            return false;
        }

        if (preg_match('/^[A-Za-z0-9+\/=_-]{40,}$/', $text)) {
            return false;
        }

        return str_contains($text, ' ');
    }

    /**
     * Play embeds its strings inside JavaScript literals, so "<" arrives as
     * < and a newline as \n.
     */
    protected function decodeJsString(string $value): string
    {
        $out = str_replace(
            ['\\/', '\\"', '\\n', '\\r', '\\t'],
            ['/', '"', "\n", "\n", ' '],
            $value
        );

        $out = preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            static fn (array $m): string => mb_convert_encoding(
                pack('H*', $m[1]),
                'UTF-8',
                'UTF-16BE'
            ),
            $out
        ) ?? $out;

        return str_replace('\\\\', '\\', $out);
    }
}
