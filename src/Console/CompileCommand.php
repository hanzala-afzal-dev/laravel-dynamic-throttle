<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Afzal\DynamicThrottle\Compiler\ManifestCompileFailed;
use Afzal\DynamicThrottle\Compiler\ManifestCompiler;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * For deploy scripts and the five-minute schedule (SPEC-006 AC-10, AC-11).
 */
final class CompileCommand extends Command
{
    protected $signature = 'dynamic-throttle:compile';

    protected $description = 'Compile the rules into the manifest that requests read';

    public function handle(ManifestCompiler $compiler): int
    {
        try {
            $result = $compiler->compile();
        } catch (ManifestCompileFailed $exception) {
            $this->error('Could not compile the manifest: '.($exception->getPrevious() ?? $exception)->getMessage());

            return self::FAILURE;
        }

        $this->info("Compiled manifest version {$result->version}: {$result->rules} ".Str::plural('rule', $result->rules).", {$result->skipped} skipped.");

        return self::SUCCESS;
    }
}
