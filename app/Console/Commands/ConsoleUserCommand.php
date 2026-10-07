<?php

namespace App\Console\Commands;

use App\Models\ConsoleUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * `php artisan console:user` — the only way a staff account comes into being.
 *
 * There is no registration page, and there is no `--password` flag. A new
 * account has a null password and is unusable until somebody follows a
 * 30-minute set-password link, which this command prints. That is deliberate:
 * a password typed on a command line ends up in the shell history, in the
 * process list while it runs, and in whatever message it gets pasted into.
 *
 * The link is printed rather than emailed when mail is not configured yet,
 * which on a fresh deploy it usually is not. Printed is honest; silently
 * failing to send is not.
 */
class ConsoleUserCommand extends Command
{
    protected $signature = 'console:user
        {email? : The staff member\'s email address}
        {--name= : Their name, for the audit log}
        {--role=support : support | billing | admin}
        {--reset : Re-issue a set-password link for an existing account}
        {--deactivate : Switch an account off without deleting it}
        {--list : List the staff accounts and stop}';

    protected $description = 'Create a console (back office) account, or re-issue its set-password link';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->list();
        }

        $email = strtolower(trim((string) ($this->argument('email') ?? $this->ask('Email address'))));

        $validator = Validator::make(
            ['email' => $email, 'role' => $this->option('role')],
            [
                'email' => ['required', 'email', 'max:191'],
                'role' => ['required', Rule::in(['support', 'billing', 'admin'])],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::INVALID;
        }

        $existing = ConsoleUser::query()->where('email', $email)->first();

        if ($this->option('deactivate')) {
            return $this->deactivate($existing, $email);
        }

        if ($existing !== null && ! $this->option('reset')) {
            $this->components->error("{$email} already has a console account. Use --reset to issue a new set-password link.");

            return self::FAILURE;
        }

        $user = $existing ?? new ConsoleUser;
        $user->forceFill([
            'name' => (string) ($this->option('name') ?: $existing?->name ?: Str::before($email, '@')),
            'email' => $email,
            'role' => (string) $this->option('role'),
            'active' => true,
            // A reset removes the old password as well as issuing a link, so a
            // compromised one stops working the moment the reset is run.
            'password' => null,
        ])->save();

        $this->components->info(($existing === null ? 'Created ' : 'Reset ').$email." ({$user->role})");

        $this->line('');
        $this->line('  Set-password link, good for '.config('console.password_link_minutes').' minutes:');
        $this->line('  '.$this->issueLink($email));
        $this->line('');
        $this->comment('  It is a credential. Send it the way you would send a password, and only to them.');

        return self::SUCCESS;
    }

    /**
     * A single-use token in `console_password_reset_tokens`, hashed at rest, so
     * that reading the table does not hand anybody a way in.
     */
    private function issueLink(string $email): string
    {
        $token = Str::random(64);

        DB::table('console_password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($token), 'created_at' => now()],
        );

        return rtrim((string) config('app.url'), '/').'/console/set-password/'.$token.'?email='.urlencode($email);
    }

    private function deactivate(?ConsoleUser $user, string $email): int
    {
        if ($user === null) {
            $this->components->error("{$email} has no console account.");

            return self::FAILURE;
        }

        // Switched off, password cleared, row kept: `console_audit` points at
        // this id and an audit trail with a dangling author is worse than a
        // disabled row.
        $user->forceFill(['active' => false, 'password' => null])->save();
        DB::table('console_password_reset_tokens')->where('email', $email)->delete();

        $this->components->info("{$email} can no longer sign in. The account and its audit history are kept.");

        return self::SUCCESS;
    }

    private function list(): int
    {
        $users = ConsoleUser::query()->orderBy('email')->get();

        if ($users->isEmpty()) {
            $this->components->warn('No console accounts yet. `php artisan console:user you@example.com` makes the first one.');

            return self::SUCCESS;
        }

        $this->table(
            ['Email', 'Name', 'Role', 'Signs in?', 'Last seen'],
            $users->map(fn (ConsoleUser $u) => [
                $u->email,
                $u->name,
                $u->role,
                $u->active ? ($u->password === null ? 'not yet — link unused' : 'yes') : 'no — switched off',
                $u->last_login_at?->diffForHumans() ?? 'never',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
