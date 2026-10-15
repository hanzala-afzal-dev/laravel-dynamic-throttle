<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Afzal\DynamicThrottle\Compiler\CompileResult;
use Afzal\DynamicThrottle\Compiler\ManifestCompileFailed;
use Afzal\DynamicThrottle\Support\ChangeOutcome;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Afzal\DynamicThrottle\Support\TableMissing;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the commands that change a setting or a rule share (SPEC-013): the
 * confirmation in production (ADR-0015 point 4), the CLI's actor, and how a
 * change's outcome is told. TakesAnEndTime and NamesARule add the options and the
 * argument only some of them have.
 *
 * @internal
 */
abstract class ChangeCommand extends Command
{
    use ConfirmableTrait;

    /**
     * Makes a change and tells its outcome: 0 when it is saved and compiled, or when
     * there was nothing to change, and 1 otherwise.
     *
     * @param  Closure(Actor): ChangeOutcome  $change  given the CLI's actor
     */
    protected function change(Closure $change, string $done, string $unchanged): int
    {
        try {
            $outcome = $change(Actor::cli());
        } catch (TableMissing $missing) {
            return $this->refuse($missing->getMessage());
        } catch (Throwable $exception) {
            return $this->refuse('Could not save the change: '.$exception->getMessage());
        }

        if (! $outcome->changed) {
            $this->info(Printable::text($unchanged));

            return self::SUCCESS;
        }

        // Servers apply a change only once it is compiled, so only then does the command say what they apply (the review).
        if ($outcome->compileFailed instanceof ManifestCompileFailed) {
            $failure = $outcome->compileFailed->getPrevious() ?? $outcome->compileFailed;
            $this->error(Printable::text('The change is saved, but the manifest could not be compiled: '.$failure->getMessage()));
            $this->line('Servers keep the previous manifest until a compile succeeds: run php artisan dynamic-throttle:compile'.($this->scheduled() ? ', or wait for the scheduled one.' : '.'));

            return self::FAILURE;
        }

        $this->info(Printable::text($done));
        $this->line($outcome->compiled instanceof CompileResult
            ? "Compiled manifest version {$outcome->compiled->version}. Servers apply it {$this->propagation()}."
            : 'The manifest compiles when the surrounding transaction commits.');

        return self::SUCCESS;
    }

    /**
     * Confirms a dangerous change in production, as Laravel's own commands do: --force
     * skips the question. Without a terminal nobody can answer it, so the change is
     * refused, and the message says to pass --force.
     */
    protected function confirmed(string $warning): bool
    {
        if (! $this->input->isInteractive() && $this->option('force') !== true && $this->laravel->environment() === 'production') {
            $this->error(Printable::text("{$warning}: in production, pass --force to confirm without a terminal."));

            return false;
        }

        return $this->confirmToProceed(Printable::text($warning));
    }

    protected function refuse(string $message): int
    {
        $this->error(Printable::text($message));

        return self::FAILURE;
    }

    protected function time(CarbonInterface $time): string
    {
        return $time->format('Y-m-d H:i:s T');
    }

    /**
     * Whether the scheduler compiles every five minutes (SPEC-006), so a failed compile heals by itself.
     */
    private function scheduled(): bool
    {
        return $this->laravel->make('config')->get('dynamic-throttle.manifest.schedule') !== false;
    }

    /**
     * When servers apply a compiled change: on their next read of the manifest.
     */
    private function propagation(): string
    {
        $refresh = $this->laravel->make('config')->get('dynamic-throttle.manifest.refresh_seconds');

        return is_int($refresh) && $refresh > 0 ? "within {$refresh} ".Str::plural('second', $refresh) : 'on their next request';
    }
}
