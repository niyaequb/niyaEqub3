<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * TEMPORARY. Delete this file and its route once the sign-in fault is closed.
 *
 * WHY IT EXISTS
 *
 * POST /api/auth/login returns HTTP 504 — a gateway timeout, meaning the
 * application never answered at all. Everything around it is demonstrably
 * healthy: GET /up renders in 154ms, GET /api returns its index, GET
 * /api/health reports database ok and cache ok, GET /api/settings reads rows
 * out of the database, and GET /api/auth/login correctly returns 405. So the
 * app boots, routes, reaches the database and answers quickly — yet one POST
 * handler hangs.
 *
 * A 504 carries no body, no stack trace and no log line, because nothing
 * failed: something was merely slow. Reading source cannot tell you which
 * statement. So this endpoint runs each step of the login path in isolation
 * and reports how long each one took.
 *
 * SAFE TO EXPOSE, DELIBERATELY
 *
 * It changes nothing and returns nothing sensitive: timings, a PHP version, a
 * cost factor, and booleans for whether a key is present — never the key,
 * never a token, never a row. The hash is made over a fixed literal, the JWT
 * is minted for an unsaved model with id 0 and discarded, and the lock test
 * runs inside a transaction that is always rolled back.
 *
 * IT MUST NOT HANG ITSELF
 *
 * A diagnostic that times out reports nothing, which is worse than useless.
 * Three guards, and the first two are the ones that matter, because a budget
 * checked *between* steps cannot interrupt a step already blocked:
 *
 *   1. Lock waits are capped per-session. `innodb_lock_wait_timeout` is set to
 *      a few seconds, so a blocked write returns an error fast instead of
 *      waiting out MySQL's 50-second default — and that error is the finding.
 *   2. bcrypt is gated on its cost factor, read from config first. The work
 *      doubles per step, so a high enough factor would time this endpoint out
 *      exactly as it times out login. When the number is itself the answer,
 *      there is no reason to pay it.
 *   3. A cumulative budget stops new steps once earlier ones have been slow,
 *      so a partial answer still comes back readable.
 */
class DiagnosticsController extends Controller
{
    /** Stop starting new steps once this much time has been spent. */
    private const BUDGET_MS = 12000;

    /**
     * Above this bcrypt cost the hash is described rather than performed.
     *
     * 12 is Laravel's default, roughly a quarter-second. 15 is about two
     * seconds; 18 is a quarter-minute; 20 is over a minute — enough on its own
     * to time out login and registration while every other endpoint stays
     * fast, which is precisely the shape of the fault being hunted.
     */
    private const MAX_SAFE_BCRYPT_ROUNDS = 13;

    /** Seconds a statement may wait for a row lock before giving up. */
    private const LOCK_WAIT_SECONDS = 5;

    public function auth(): JsonResponse
    {
        $started = microtime(true);
        $steps = [];

        $driver = (string) config('hashing.driver', 'bcrypt');
        $rounds = (int) config('hashing.bcrypt.rounds', 12);

        // Collected before anything is measured: these facts alone explain
        // most sign-in faults and cost nothing to read.
        $facts = [
            'php_version' => PHP_VERSION,
            'app_env' => (string) config('app.env'),
            'app_debug' => (bool) config('app.debug'),
            'hash_driver' => $driver,
            'bcrypt_rounds' => $rounds,
            'bcrypt_rounds_verdict' => $driver === 'bcrypt'
                ? $this->describeRounds($rounds)
                : 'not applicable — the active hash driver is '.$driver.', not bcrypt',
            'jwt_secret_present' => filled(config('jwt.secret')),
            'jwt_algo' => (string) config('jwt.algo', 'unknown'),
            'db_connection' => (string) config('database.default'),
            'cache_store' => (string) config('cache.default'),
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
        ];

        $elapsed = static fn (): float => (microtime(true) - $started) * 1000;

        $run = function (string $name, callable $work) use (&$steps, $elapsed): void {
            if ($elapsed() > self::BUDGET_MS) {
                // Same shape as every other step. A consumer reading
                // `steps.*.ok` should never meet an undefined key.
                $steps[$name] = [
                    'ms' => null,
                    'ok' => false,
                    'skipped' => 'time budget already spent by an earlier step',
                ];

                return;
            }

            $at = microtime(true);

            try {
                $detail = $work();
                $steps[$name] = [
                    'ms' => round((microtime(true) - $at) * 1000, 1),
                    'ok' => true,
                ] + (is_array($detail) ? $detail : []);
            } catch (Throwable $e) {
                $steps[$name] = [
                    'ms' => round((microtime(true) - $at) * 1000, 1),
                    'ok' => false,
                    // Class and message only. Both are ours, neither carries a
                    // credential, and without them a failed step says nothing.
                    'error' => $e::class,
                    'message' => $e->getMessage(),
                ];
            }
        };

        // Cap lock waits for THIS connection only. Without it a contended row
        // blocks for innodb_lock_wait_timeout, which defaults to 50 seconds —
        // long enough to time out this endpoint and destroy its whole purpose.
        // Wrapped because it is MySQL-specific and must not break the run on
        // any other engine.
        $run('session_limits', static function (): array {
            // BOTH matter, and they cap different waits. innodb_lock_wait_timeout
            // bounds a wait for a ROW lock and defaults to 50 seconds.
            // lock_wait_timeout bounds a wait for a METADATA lock — the kind a
            // running ALTER TABLE holds — and defaults to a year. Capping only
            // the first would leave the "something else is holding the users
            // table" case, which is precisely one of the things being hunted,
            // completely unbounded.
            DB::statement('set session innodb_lock_wait_timeout = '.self::LOCK_WAIT_SECONDS);
            DB::statement('set session lock_wait_timeout = '.self::LOCK_WAIT_SECONDS);

            return ['lock_wait_seconds' => self::LOCK_WAIT_SECONDS];
        });

        // 1. Plain connectivity, as a baseline for every number below.
        $run('db_select_1', static function (): array {
            DB::select('select 1');

            return [];
        });

        // 2. The read login starts with.
        $run('db_users_read', static function (): array {
            return ['found_any' => User::query()->limit(1)->exists()];
        });

        // 3. THE ROW LOCK. login's first write is $user->updateLastLogin().
        //    A no-op UPDATE would be useless here: matching no row takes no row
        //    lock, so it would report fast and wrongly exonerate the write.
        //    SELECT ... FOR UPDATE takes a real lock on a real row and so waits
        //    on exactly what an UPDATE would wait on — then rolls back, so
        //    nothing is changed and the lock is released immediately. If some
        //    other transaction is sitting on that row, this returns a lock-wait
        //    error in a few seconds and that is the entire answer.
        //
        //    Attempted ONLY if the caps above actually applied. Taking a row
        //    lock with an unbounded wait is how this endpoint would hang on the
        //    very contention it exists to detect — and a guard whose own
        //    failure goes unnoticed is not a guard.
        if (($steps['session_limits']['ok'] ?? false) === true) {
            $run('db_row_lock', static function (): array {
                try {
                    DB::beginTransaction();
                    $rows = DB::select('select id from users order by id limit 1 for update');

                    return ['locked_a_row' => $rows !== []];
                } finally {
                    // Swallowed deliberately. A rollback that fails on a dropped
                    // connection would otherwise REPLACE the exception that
                    // actually explains the failure, and correct attribution is
                    // this endpoint's entire job.
                    try {
                        DB::rollBack();
                    } catch (Throwable) {
                        // Nothing useful to do, and nothing worth losing.
                    }
                }
            });
        } else {
            $steps['db_row_lock'] = [
                'ms' => null,
                'ok' => false,
                'skipped' => 'the lock-wait cap could not be applied, so taking a row lock here could block indefinitely',
            ];
        }

        // 4. HASHING — the prime suspect. It is the only expensive work that
        //    login and registration share and that no healthy endpoint does,
        //    which is exactly this fault's shape: those two hang, everything
        //    else is fast. bcrypt cost is exponential, so a cost factor raised
        //    in an environment variable turns a 250ms sign-in into a
        //    minute-long one with no error recorded anywhere.
        $costGate = $this->hashCostGate($driver, $rounds);

        if ($costGate !== null) {
            // Both steps are emitted, not just one. The set of keys must not
            // change with the outcome, or anything reading this output has to
            // special-case the very run that found the fault.
            foreach (['hash_make', 'hash_verify'] as $skipped) {
                $steps[$skipped] = ['ms' => null, 'ok' => false, 'skipped' => $costGate];
            }
        } else {
            // Named for what it measures rather than for the algorithm, so the
            // key does not change with the driver or the outcome.
            $run('hash_make', static function (): array {
                Hash::make('diagnostic-not-a-real-password');

                return [];
            });

            $run('hash_verify', static function (): array {
                // login calls Hash::check against a stored hash. Verifying
                // costs the same as making, so this is the honest number.
                $hash = Hash::make('diagnostic-not-a-real-password');

                return ['matched' => Hash::check('diagnostic-not-a-real-password', $hash)];
            });
        }

        // 5. Signing. The step that produced the original HTTP 500 before
        //    JWT_SECRET was addressed. Minted for an unsaved model and thrown
        //    away — never returned, never valid for a real account.
        $run('jwt_mint', static function (): array {
            $subject = new User;
            $subject->id = 0;

            return ['length' => strlen((string) JWTAuth::fromUser($subject))];
        });

        return response()->json([
            'status' => 'success',
            'note' => 'Temporary sign-in diagnostic. Delete this route once the fault is closed.',
            'facts' => $facts,
            'steps' => $steps,
            'slowest_step' => $this->slowest($steps),
            'total_ms' => round($elapsed(), 1),
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * Why hashing must not be run, or null when it is safe to measure.
     *
     * Driver-agnostic on purpose. Gating on bcrypt's cost factor alone left
     * Argon completely ungated, and a mis-set ARGON_MEMORY or ARGON_TIME times
     * out a sign-in every bit as readily as a mis-set BCRYPT_ROUNDS — so the
     * endpoint could still hang on the exact class of fault it exists to find.
     */
    private function hashCostGate(string $driver, int $rounds): ?string
    {
        if ($driver === 'bcrypt') {
            return $rounds > self::MAX_SAFE_BCRYPT_ROUNDS
                ? 'bcrypt cost factor '.$rounds.' is high enough to time this endpoint out, which is itself the finding'
                : null;
        }

        // Argon2's cost is memory x iterations. Defaults are 65536 KiB and 3-4
        // passes; an order of magnitude above either is seconds per hash.
        $memory = (int) config('hashing.argon.memory', 65536);
        $time = (int) config('hashing.argon.time', 4);

        if ($memory > 262144 || $time > 8) {
            return $driver.' is configured with memory='.$memory.'KiB and time='.$time
                .', far above the defaults of 65536 and 4. That alone can time out a sign-in, which is itself the finding';
        }

        return null;
    }

    /**
     * Name of the step that took longest, or null if none completed.
     *
     * @param  array<string, array<string, mixed>>  $steps
     */
    private function slowest(array $steps): ?string
    {
        $name = null;
        $worst = -1.0;

        foreach ($steps as $step => $result) {
            $ms = $result['ms'] ?? null;

            if (is_numeric($ms) && (float) $ms > $worst) {
                $worst = (float) $ms;
                $name = $step;
            }
        }

        return $name;
    }

    /**
     * Put the bcrypt cost factor in plain terms.
     *
     * The bare number means nothing to most people reading a diagnostic at two
     * in the morning, and being readable without knowing that bcrypt doubles
     * per step is the entire point.
     */
    private function describeRounds(int $rounds): string
    {
        return match (true) {
            $rounds <= 0 => 'unset or invalid — Laravel falls back to its default of 12',
            $rounds < 10 => 'LOW ('.$rounds.'). Fast, but weaker than recommended.',
            $rounds <= 12 => 'normal ('.$rounds.'). Laravel default is 12, roughly a quarter of a second.',
            $rounds <= 14 => 'high ('.$rounds.'). One to two seconds per sign-in. Slow but survivable.',
            $rounds <= 16 => 'VERY HIGH ('.$rounds.'). Several seconds per sign-in. A plausible cause of timeouts.',
            default => 'EXTREME ('.$rounds.'). Tens of seconds to minutes per sign-in. This alone produces a gateway timeout on login and registration while every other endpoint stays fast.',
        };
    }
}
