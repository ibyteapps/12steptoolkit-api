<?php

namespace App\Console\Commands\Concerns;

use App\Exceptions\CheckCaution;
use Throwable;

/**
 * The shared shape of this application's diagnostic commands: a list of named
 * checks, each printed as it runs, and an exit code that means something.
 *
 * Three outcomes, not two:
 *
 *  * **✓ pass** — with the detail that proves it, because "✓ connects" without
 *    the database name has told you very little;
 *  * **! caution** — true today, and somebody has to come back to it. Counted,
 *    reported, and deliberately **does not** change the exit code, so the
 *    command stays usable as the body of a monitor;
 *  * **✗ failure** — exit code 1.
 *
 * A check never stops the run. The twenty minutes after a cutover is exactly
 * when you want the whole list, not the first thing that broke.
 */
trait ReportsChecks
{
    private int $failures = 0;

    private int $cautions = 0;

    /**
     * Print a heading and, when [$body] is given, run that section under it.
     *
     * The try/catch is the backstop: a section usually fails one check at a
     * time, but a section that gathers something *before* its first check —
     * a list of tables, a collation query — can throw outside one, and until
     * this existed that ended the whole run in a stack trace with half the
     * checks never printed. Which is the opposite of what a diagnostic is for.
     *
     * @param  callable():void|null  $body
     */
    private function section(string $title, ?callable $body = null): void
    {
        $this->line('  <options=bold>'.$title.'</>');

        if ($body === null) {
            return;
        }

        try {
            $body();
        } catch (CheckCaution $e) {
            $this->cautions++;
            $this->line(sprintf('  <fg=yellow>!</> %s', $e->getMessage()));
            $this->newLine(0);
        } catch (Throwable $e) {
            $this->failures++;
            $this->line(sprintf('  <fg=red>✗</> this section could not run <fg=red>%s</>', $e->getMessage()));
            $this->newLine(0);
        }
    }

    /**
     * Run one check. The probe returns the detail to print, throws
     * [CheckCaution] for something to come back to, or throws anything else to
     * fail.
     *
     * @param  callable():string  $probe
     */
    private function check(string $label, callable $probe): void
    {
        try {
            $detail = $probe();
            $this->line(sprintf('  <fg=green>✓</> %s <fg=gray>%s</>', $label, $detail));
        } catch (CheckCaution $e) {
            $this->cautions++;
            $this->line(sprintf('  <fg=yellow>!</> %s <fg=yellow>%s</>', $label, $e->getMessage()));
        } catch (Throwable $e) {
            $this->failures++;
            $this->line(sprintf('  <fg=red>✗</> %s <fg=red>%s</>', $label, $e->getMessage()));
        }

        $this->newLine(0);
    }

    /** A line that is information rather than a verdict. */
    private function note(string $text): void
    {
        $this->line('  <fg=gray>·</> <fg=gray>'.$text.'</>');
    }

    /** Print the tally and return the exit code. */
    private function report(): int
    {
        $this->line('');

        if ($this->failures > 0) {
            $this->components->error(
                $this->failures.' '.str('check')->plural($this->failures).' failed'
                .($this->cautions > 0 ? ', '.$this->cautions.' to look at' : ''),
            );

            return self::FAILURE;
        }

        if ($this->cautions > 0) {
            $this->components->warn(
                'Everything essential is working. '.$this->cautions.' '
                .str('thing')->plural($this->cautions).' to look at.',
            );

            return self::SUCCESS;
        }

        $this->components->info('Everything checked out.');

        return self::SUCCESS;
    }
}
