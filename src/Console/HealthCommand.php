<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\CheckStatus;
use Afzal\DynamicThrottle\Support\Health\HealthChecks;
use Afzal\DynamicThrottle\Support\Health\HealthReport;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks what limits less than the admin thinks, or nothing at all (SPEC-016): the
 * config and the storage, the routes, the manifest and the flush, and what the
 * rules saw in the last hour. One line per check, or one JSON object. It exits 1
 * when a check errs, and with --strict when one warns too, so a deploy script
 * can choose. It changes nothing.
 */
final class HealthCommand extends Command
{
    protected $signature = 'dynamic-throttle:health
        {--json : Print the report as JSON}
        {--strict : Exit 1 on a warning too}';

    protected $description = 'Check the config, the routes, the store and what the rules saw';

    public function handle(HealthChecks $checks): int
    {
        $report = $checks->run();

        if ($this->option('json') === true) {
            // As is: the console formatter would read a rule's name such as "<fg=red>" as a style.
            $this->output->writeln(Printable::json($report), OutputInterface::OUTPUT_RAW);
        } else {
            $this->render($report);
        }

        return match (true) {
            $report->status() === CheckStatus::Error => self::FAILURE,
            $report->status() === CheckStatus::Warning && $this->option('strict') === true => self::FAILURE,
            default => self::SUCCESS,
        };
    }

    private function render(HealthReport $report): void
    {
        $this->newLine();

        foreach ($report->results as $result) {
            $this->line('  '.$this->status($result).'  '.str_pad($result->check, 16).'  '.Printable::text($result->message));
        }

        $warnings = $report->count(CheckStatus::Warning);
        $errors = $report->count(CheckStatus::Error);
        $this->newLine();
        $this->line("  {$warnings} ".Str::plural('warning', $warnings).", {$errors} ".Str::plural('error', $errors).'.');
    }

    /**
     * The status in capitals, in its colour, as wide as the widest.
     */
    private function status(CheckResult $result): string
    {
        $colour = match ($result->status) {
            CheckStatus::Ok => 'green',
            CheckStatus::Warning => 'yellow',
            CheckStatus::Error => 'red',
            CheckStatus::Skipped => 'gray',
        };

        return "<fg={$colour}>".str_pad(strtoupper($result->status->value), 7).'</>';
    }
}
