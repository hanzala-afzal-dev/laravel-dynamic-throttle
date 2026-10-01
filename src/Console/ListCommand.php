<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Afzal\DynamicThrottle\Models\Rule;
use Illuminate\Console\Command;

/**
 * Lists the rules in the database, disabled ones too, in the manifest's order,
 * with the ids that disable-rule and enable-rule take (SPEC-013 AC-17).
 */
final class ListCommand extends Command
{
    protected $signature = 'dynamic-throttle:list';

    protected $description = 'List the rules, with the ids the other commands take';

    public function handle(): int
    {
        // Raw rows, so a row the model would refuse still shows during an incident.
        $rows = Rule::query()->orderBy('priority')->orderBy('id')->toBase()->get();

        if ($rows->isEmpty()) {
            $this->info('There are no rules yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Method', 'Pattern', 'Scope', 'Limit', 'Mode', 'On failure', 'Enabled'],
            $rows->map(fn (object $row): array => $this->row((array) $row))->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return list<string>
     */
    private function row(array $row): array
    {
        $parameter = $this->text($row['scope_parameter'] ?? null);

        return [
            $this->text($row['id'] ?? null),
            $this->text($row['name'] ?? null),
            $this->text($row['method'] ?? null),
            $this->text($row['pattern'] ?? null),
            $this->text($row['scope'] ?? null).($parameter === '' ? '' : ":{$parameter}"),
            $this->text($row['max_attempts'] ?? null).' per '.$this->text($row['window_seconds'] ?? null).'s',
            $this->text($row['mode'] ?? null),
            $this->text($row['on_failure'] ?? null),
            // SQLite and MySQL return 1 or 0, PostgreSQL a boolean.
            in_array($row['enabled'] ?? null, [true, 1, '1'], true) ? 'yes' : 'no',
        ];
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
