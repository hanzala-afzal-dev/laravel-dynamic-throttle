<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Afzal\DynamicThrottle\Models\Rule;
use Throwable;

/**
 * The id argument, for the commands that change one rule (SPEC-013).
 *
 * @internal
 */
trait NamesARule
{
    /**
     * The rule the id argument names, or null when there is none.
     *
     * @throws Throwable from the database
     */
    protected function rule(): ?Rule
    {
        return preg_match('/\A[1-9]\d{0,17}\z/', $this->id()) === 1 ? Rule::query()->find((int) $this->id()) : null;
    }

    /**
     * The rule the id argument names, or why there is none, as the commands print it.
     */
    protected function ruleOrRefusal(): Rule|int
    {
        try {
            $rule = $this->rule();
        } catch (Throwable $exception) {
            return $this->refuse("Could not read rule {$this->id()}: ".$exception->getMessage());
        }

        return $rule ?? $this->refuse("There is no rule {$this->id()}.");
    }

    /**
     * A rule as messages name it: its id, and its name as text (the review).
     */
    protected function label(Rule $rule): string
    {
        return "Rule {$rule->id} ({$rule->name})";
    }

    /**
     * The id argument, as given: a string from a terminal, or a number from Artisan::call(),
     * which Larastan's string type for argument() does not foresee.
     */
    protected function id(): string
    {
        $id = $this->input->getArgument('id');

        return is_scalar($id) ? (string) $id : '';
    }
}
