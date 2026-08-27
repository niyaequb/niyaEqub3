<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Firebase service account, stored somewhere that survives a deploy.
 *
 * THE PROBLEM THIS SOLVES
 *
 * The admin Settings page lets someone upload a service-account JSON, and it
 * was written to storage/app/firebase/service-account.json and read back from
 * there. On a server with a persistent disk that is fine. On App Platform it
 * is the same trap that made the Settings page appear to lose everything: the
 * container is rebuilt from the build image on every deploy, restart and scale
 * event, so the uploaded file is destroyed. storage/ is not in the image
 * either. The failure is quiet and delayed — push notifications simply stop,
 * days later, with no error on the screen where the file was uploaded.
 *
 * HOW IT WORKS
 *
 * The JSON is kept in the settings table, which persists. The file is treated
 * as a cache of it: `path()` writes the file back from the stored copy
 * whenever it is missing, so every existing caller that wants a file path —
 * FcmService, the Kreait factory, anything else — keeps working unchanged and
 * simply finds the file there.
 *
 * Writing the file on demand rather than at boot keeps the cost where it
 * belongs: a request that never sends a notification never touches the disk.
 *
 * ON KEEPING A PRIVATE KEY IN THE DATABASE
 *
 * It is signing material, and it is now a row rather than a file. That is the
 * same trade already made for the payment gateways' app secrets, and it is the
 * better of the two available risks: the alternative is not "the key is safer",
 * it is "push notifications break on every deploy and nobody notices". Anyone
 * with database access could already read the payment credentials.
 */
class FirebaseCredentials
{
    /**
     * Where the stored JSON lives, as an EnvService key.
     */
    public const KEY = 'FIREBASE_SERVICE_ACCOUNT_JSON';

    public function __construct(protected EnvService $env)
    {
    }

    /**
     * The canonical location callers expect the file at.
     */
    public function filePath(): string
    {
        return storage_path('app/firebase/service-account.json');
    }

    /**
     * Persist an uploaded service account.
     *
     * Stored first, written second. If the disk write fails the credentials
     * are still safe, and the next call to path() will lay the file down
     * again; if the order were reversed a failed store would leave a file that
     * disappears at the next deploy with nothing behind it.
     */
    public function store(string $json): bool
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! isset($decoded['project_id'], $decoded['client_email'], $decoded['private_key'])) {
            throw new \RuntimeException(
                'That does not look like a Firebase service account file. '
                .'Expected JSON containing project_id, client_email and private_key.'
            );
        }

        // Re-encoded rather than stored verbatim: it normalises whatever the
        // upload contained and guarantees what comes back out is parseable.
        if (! $this->env->set(self::KEY, (string) json_encode($decoded))) {
            // The file is deliberately NOT written in this case, and success
            // is deliberately not reported. A file with no stored copy behind
            // it is the precise failure this class exists to prevent: it works
            // today, vanishes at the next deploy, and takes push notifications
            // down days later with nothing on any screen connecting the two.
            // Far better to fail here, in front of the person who just clicked
            // save.
            throw new \RuntimeException(
                'The service account could not be written to the settings table, so it has not been saved. '
                .'Check the database connection and that migrations have run.'
            );
        }

        // A failed file write is survivable and is not raised: the stored copy
        // is the record, and path() lays the file down again on next use.
        $this->writeFile((string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return true;
    }

    /**
     * The service account as an array, from wherever it can be found.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $stored = (string) $this->env->get(self::KEY, '');

        if ($stored !== '') {
            $decoded = json_decode($stored, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Nothing stored, but a file may still be there — a deployment that
        // ships one, or an install predating this class.
        $path = $this->filePath();

        if (is_readable($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * A readable path to the service account file, restoring it if need be.
     *
     * Returns null when there are no credentials at all, so a caller can say
     * so rather than handing a path to a file that is not there.
     */
    public function path(): ?string
    {
        $path = $this->filePath();

        if (is_readable($path)) {
            return $path;
        }

        $stored = $this->json();

        if ($stored === null) {
            return null;
        }

        Log::info('Restoring the Firebase service account file from stored settings.', ['path' => $path]);

        return $this->writeFile((string) json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            ? $path
            : null;
    }

    /**
     * True when credentials exist in either place.
     */
    public function exists(): bool
    {
        return $this->json() !== null;
    }

    protected function writeFile(string $contents): bool
    {
        try {
            $path = $this->filePath();
            $directory = dirname($path);

            if (! is_dir($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            File::put($path, $contents);

            // The file holds a private key and lives under a path the web
            // server can reach. Not world-readable, on the platforms where
            // that means anything.
            @chmod($path, 0600);

            return true;
        } catch (Throwable $e) {
            Log::error('Could not write the Firebase service account file.', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
