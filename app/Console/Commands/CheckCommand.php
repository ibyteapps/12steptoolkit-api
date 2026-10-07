<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\InstallSecret;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * `php artisan app:check` — is this deployment actually able to do its job?
 *
 * Written for the twenty minutes after a cutover, when the question is not
 * "does the code work" (the test suite answers that) but "is *this server*
 * wired up". Every check is something that has gone wrong on a Plesk vhost
 * before: a missing cron, an unwritable storage directory, a database the
 * application can reach but whose adopted tables are not the shape it expects.
 *
 * It reads. It writes nothing except one cache key, and it never selects a
 * content column, so it is safe to run on production while people are using it.
 *
 * Exit code 1 if anything failed, so it can be the body of a monitor.
 */
class CheckCommand extends Command
{
    protected $signature = 'app:check {--legacy : Also check the adopted tables, column by column}';

    protected $description = 'Check that this deployment is wired up: database, adopted schema, cache, queue, scheduler, storage, secrets';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $this->line('');

        $this->section('Database');
        $this->database();

        $this->section('The adopted tables');
        $this->adoptedTables();

        $this->section('Character sets');
        $this->charsets();

        if ($this->option('legacy')) {
            $this->section('The adopted columns');
            $this->adoptedColumns();
        }

        $this->section('This application\'s own tables');
        $this->ownTables();

        $this->section('Cache, queue and scheduler');
        $this->runtime();

        $this->section('Storage');
        $this->storage();

        $this->section('Secrets and switches');
        $this->secrets();

        $this->line('');

        if ($this->failures > 0) {
            $this->components->error($this->failures.' '.str('check')->plural($this->failures).' failed'.($this->warnings > 0 ? ", {$this->warnings} to look at" : ''));

            return self::FAILURE;
        }

        if ($this->warnings > 0) {
            $this->components->warn('Everything essential is working. '.$this->warnings.' '.str('thing')->plural($this->warnings).' to look at.');

            return self::SUCCESS;
        }

        $this->components->info('Everything checked out.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------- the checks

    private function database(): void
    {
        $this->check('connects', function (): string {
            $name = DB::connection()->getDatabaseName();
            DB::select('select 1');

            return $name;
        });

        $this->check('is the right one', function (): string {
            $name = (string) DB::connection()->getDatabaseName();

            // Not a hard failure: staging is a copy under another name. But a
            // database with no `accounts` table is not this product's database,
            // and that IS worth stopping for.
            if (! Schema::hasTable('accounts')) {
                throw new \RuntimeException("`{$name}` has no `accounts` table — this is not the 12 Step Toolkit database");
            }

            return $name;
        });
    }

    /**
     * The tables this application adopts and must never alter. If one is
     * missing, something is pointed at the wrong database.
     */
    private function adoptedTables(): void
    {
        foreach ([
            'accounts', 'account_details', 'appsettings', 'app_settings', 'serverstatus',
            'inventories', 'amends', 'nights', 'mornings', 'journals', 'gratitudes',
            'reviewed', 'sponsors', 'comments', 'comment_threads', 'comment_thread_subscribers',
            'reported_users', 'blocked_users', 'icons', 'devices', 'reminder_subscriptions',
            'orders', 'subscription_orders', 'sponsee_orders', 'sponsee_order_users',
        ] as $table) {
            $this->check($table, function () use ($table): string {
                if (! Schema::hasTable($table)) {
                    throw new \RuntimeException('missing');
                }

                return number_format(DB::table($table)->count()).' rows';
            });
        }

        /*
         | `install_secrets` is the cutover blocker. It was added by the 2024
         | Android security patch set, which as far as I can tell was never
         | deployed. Without it every signed Android request fails closed.
         | docs/OPEN_QUESTIONS.md A4.
         */
        $this->check('install_secrets', function (): string {
            if (! Schema::hasTable('install_secrets')) {
                throw new \RuntimeException(
                    'missing — every signed Android request will fail. See docs/OPEN_QUESTIONS.md A4'
                );
            }

            $active = InstallSecret::query()->where('status', InstallSecret::ACTIVE)->count();

            // One row per (account, device) is enforced by uq_account_device.
            // If that key is missing, rotation can leave two live keys for one
            // install and whichever verifies first wins.
            if (DB::connection()->getDriverName() === 'mysql') {
                $unique = collect(DB::select('SHOW INDEXES FROM install_secrets'))
                    ->contains(fn ($i) => (int) $i->Non_unique === 0 && $i->Key_name !== 'PRIMARY');

                if (! $unique) {
                    throw new \RuntimeException('exists, but has no UNIQUE (account_id, device_id) — rotation is unsafe');
                }
            }

            if ($active === 0 && Account::query()->count() > 0) {
                throw new \RuntimeException('exists but holds no active secret — no Android install can sign a request yet');
            }

            return number_format($active).' active, one row per device enforced';
        });
    }

    /**
     * Column by column, against what `tests/Support/LegacySchema.php` believes.
     * Slow and noisy, which is why it is behind `--legacy`: it is the check to
     * run once, the first time this application meets the real database.
     */
    /**
     * The live finding this command exists to make visible.
     *
     * `nights` and `mornings` are **latin1** while the rest of the database is
     * utf8mb4 and the v19 connection sets utf8mb4. So a character outside
     * latin1 written into a nightly review's twelve answers, or into the
     * morning notes, is converted on the way in and becomes `?`. iOS turns a
     * typed apostrophe into U+2019 automatically, so "I didn't" is stored as
     * "I didn?t" — every day, for every iOS user, in the one feature they use
     * every day.
     *
     * It cannot be fixed from here: `ALTER TABLE ... CONVERT TO CHARACTER SET`
     * alters an adopted table, which this application does not do on its own.
     * So it is reported, loudly, every time somebody runs this.
     */
    private function charsets(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->line('  <fg=gray>·</> skipped — only meaningful against MySQL/MariaDB</>');

            return;
        }

        // Tables holding text somebody typed. The rest can be any charset.
        $holdsWriting = [
            'nights' => 'the twelve nightly answers',
            'mornings' => 'the morning notes',
            'inventories' => 'titles, descriptions, fault and apology notes',
            'amends' => 'the amend and its notes',
            'journals' => 'journal entries',
            'gratitudes' => 'gratitude lists',
            'comments' => 'chat and sponsor comments',
            'quotes' => 'the daily quotes',
        ];

        $rows = DB::select(
            'SELECT TABLE_NAME AS t, TABLE_COLLATION AS c FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('.implode(',', array_fill(0, count($holdsWriting), '?')).')',
            [DB::connection()->getDatabaseName(), ...array_keys($holdsWriting)],
        );

        foreach ($rows as $row) {
            $table = (string) $row->t;
            $collation = (string) $row->c;

            $this->check($table, function () use ($collation, $table, $holdsWriting): string {
                if (! str_starts_with($collation, 'utf8mb4')) {
                    throw new \RuntimeException(
                        $collation.' — '.$holdsWriting[$table].' cannot hold an emoji, a curly '
                        ."apostrophe or any non-Latin script. They become '?' on write. "
                        .'See docs/OPEN_QUESTIONS.md'
                    );
                }

                return $collation;
            });
        }
    }

    private function adoptedColumns(): void
    {
        $expected = [
            'accounts' => ['id', 'email', 'hashed_password', 'password', 'accounttype', 'sobrietydate', 'sobrietytime', 'nickname', 'icon', 'subscribed', 'googleid', 'appleid', 'fbid', 'created', 'modified', 'deletion_timestamp'],
            'inventories' => ['id', 'accountid', 'inventoryforstep', 'invtype', 'invtitle', 'invdescription', 'affectsmyint', 'affectsmy', 'myfault', 'shared', 'shareddate', 'apologyowed', 'apologydone', 'apologydate', 'apologynotes', 'tstamp', 'reviewed'],
            'amends' => ['id', 'accountid', 'amendstitle', 'amendsfor', 'amendsdone', 'amendsdate', 'amendsnotes', 'tstamp', 'reviewed'],
            'nights' => ['id', 'accountid', 'sw1', 'sw7', 'sw9', 'sw12', 'desc1', 'desc12', 'thedate', 'tstamp', 'for_date'],
            'mornings' => ['id', 'accountid', 'icons', 'q2', 'q3', 'q4', 'q5', 'q6_notes', 'tstamp'],
            'journals' => ['id', 'accountid', 'description', 'tstamp'],
            'gratitudes' => ['id', 'accountid', 'description', 'tstamp'],
        ];

        foreach ($expected as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $missing = array_values(array_filter($columns, fn (string $c) => ! Schema::hasColumn($table, $c)));

            $this->check($table, function () use ($missing, $columns): string {
                if ($missing !== []) {
                    throw new \RuntimeException('missing '.implode(', ', $missing));
                }

                return count($columns).' columns as expected';
            });
        }

        // The one column nobody has confirmed either way. Not a failure in
        // either direction — just worth knowing. docs/OPEN_QUESTIONS.md A1.
        if (Schema::hasTable('nights')) {
            $has = Schema::hasColumn('nights', 'sw8');
            $this->line(sprintf(
                '  <fg=gray>·</> nights.sw8 %s <fg=gray>(never written either way — OPEN_QUESTIONS A1)</>',
                $has ? 'exists' : 'does not exist',
            ));
        }
    }

    private function ownTables(): void
    {
        $missing = [];

        foreach ([
            'personal_access_tokens', 'installs', 'login_codes', 'sign_ins', 'request_nonces',
            'account_security', 'console_users', 'console_audit', 'support_tickets', 'support_messages',
            'entitlements', 'store_subscriptions', 'store_orders', 'settings', 'sessions', 'jobs',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        $this->check('migrated', function () use ($missing): string {
            if ($missing !== []) {
                throw new \RuntimeException('run `php artisan migrate` — missing '.implode(', ', $missing));
            }

            return 'all present';
        });

        $this->check('a console account exists', function (): string {
            $total = DB::table('console_users')->where('active', true)->count();
            $usable = DB::table('console_users')->where('active', true)->whereNotNull('password')->count();

            if ($total === 0) {
                throw new \RuntimeException('none — `php artisan console:user you@example.com` makes the first one');
            }

            if ($usable === 0) {
                $this->warnings++;

                return "{$total}, none with a password set yet";
            }

            return "{$usable} of {$total} can sign in";
        });
    }

    private function runtime(): void
    {
        $this->check('cache reads and writes', function (): string {
            $key = 'app-check-'.bin2hex(random_bytes(4));
            Cache::put($key, 'ok', 30);

            if (Cache::get($key) !== 'ok') {
                throw new \RuntimeException('wrote a key and read something else back');
            }

            Cache::forget($key);

            return (string) config('cache.default');
        });

        $this->check('queue', function (): string {
            $driver = (string) config('queue.default');

            if ($driver === 'sync') {
                $this->warnings++;

                return 'sync — jobs run inside the request. Fine for now, not for webhooks';
            }

            $pending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

            if ($failed > 0) {
                $this->warnings++;
            }

            return "{$driver} — {$pending} waiting, {$failed} failed";
        });

        /*
         | The scheduler writes a heartbeat every minute (routes/console.php).
         | No heartbeat means the cron entry is missing, which means the queue
         | worker is not running either, which means webhooks are being
         | received and never processed. It is the quietest way for this
         | deployment to be broken.
         */
        $this->check('scheduler', function (): string {
            $beat = Cache::get('scheduler-heartbeat');

            if ($beat === null) {
                throw new \RuntimeException(
                    'no heartbeat in the last hour — add the cron entry: '
                    .'`* * * * * cd '.base_path().' && php artisan schedule:run`'
                );
            }

            $at = Carbon::parse($beat);

            if ($at->lt(now()->subMinutes(5))) {
                $this->warnings++;

                return 'last ran '.$at->diffForHumans().' — stale';
            }

            return 'ran '.$at->diffForHumans();
        });
    }

    private function storage(): void
    {
        foreach (['storage/logs', 'storage/framework/cache', 'storage/framework/views', 'bootstrap/cache'] as $path) {
            $this->check($path, function () use ($path): string {
                $full = base_path($path);

                if (! is_dir($full)) {
                    throw new \RuntimeException('does not exist');
                }

                if (! is_writable($full)) {
                    throw new \RuntimeException('not writable — `chmod -R 775 storage bootstrap/cache`');
                }

                return 'writable';
            });
        }

        $this->check('icons directory', function (): string {
            $path = (string) config('toolkit.icons.path');

            if (! is_dir($path)) {
                $this->warnings++;

                return "{$path} does not exist — profile pictures will not load";
            }

            if (str_starts_with(realpath($path) ?: '', realpath(public_path()) ?: '|')) {
                throw new \RuntimeException('is inside the web root — it must not be directly fetchable');
            }

            return 'outside the web root, as it should be';
        });
    }

    private function secrets(): void
    {
        $this->check('APP_KEY', function (): string {
            if (config('app.key') === null || config('app.key') === '') {
                throw new \RuntimeException('not set — `php artisan key:generate`');
            }

            return 'set';
        });

        $this->check('APP_DEBUG', function (): string {
            if (config('app.debug') && app()->environment('production')) {
                throw new \RuntimeException('on, in production — stack traces are being served to the internet');
            }

            return config('app.debug') ? 'on (not production)' : 'off';
        });

        $this->check('legacy v19', function (): string {
            if (! config('legacy.android.enabled')) {
                return 'switched off';
            }

            if (config('legacy.android.jwt_secret') === '') {
                throw new \RuntimeException('enabled, but LEGACY_JWT_SECRET is empty — every token in the field will be refused');
            }

            return 'on, secret set, '.config('legacy.android.hmac_window_seconds').'s window';
        });

        $this->check('legacy v8', function (): string {
            if (! config('legacy.apple.enabled')) {
                return 'switched off (the Apple layer is not built yet — that is correct)';
            }

            if (config('legacy.apple.server_secret') === '') {
                throw new \RuntimeException('enabled with an empty secret — every Apple request will be refused');
            }

            $this->warnings++;

            return 'ON — and the Apple endpoints answer 503. See IMPLEMENTATION_STATUS.md';
        });

        $this->check('plaintext password column', fn (): string => (string) config('legacy.plaintext_password')
            .(config('legacy.plaintext_password') === 'keep' ? ' — both apps work; switch to `clear` after the cutover' : ' — iOS email sign-in is ending as people sign in'));

        $this->check('sealing', fn (): string => config('legacy.seal_on_v2_login')
            ? 'on — the legacy exposure shrinks with every 2.0 sign-in'
            : 'OFF — accounts keep their weaker door open after upgrading');
    }

    // ------------------------------------------------------------- plumbing

    private function section(string $title): void
    {
        $this->line('  <options=bold>'.$title.'</>');
    }

    /** @param  callable():string  $probe */
    private function check(string $label, callable $probe): void
    {
        try {
            $detail = $probe();
            $this->line(sprintf('  <fg=green>✓</> %s <fg=gray>%s</>', $label, $detail));
        } catch (Throwable $e) {
            $this->failures++;
            $this->line(sprintf('  <fg=red>✗</> %s <fg=red>%s</>', $label, $e->getMessage()));
        }

        $this->newLine(0);
    }
}
