<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Afzal\DynamicThrottle\Engine\CounterReading;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Http\Untouched;
use Afzal\DynamicThrottle\Http\UntouchedReason;
use Afzal\DynamicThrottle\Manifest\ManifestSettings;
use Afzal\DynamicThrottle\Support\Explainer;
use Afzal\DynamicThrottle\Support\Explanation\DecisionOutcome;
use Afzal\DynamicThrottle\Support\Explanation\ExplainedDecision;
use Afzal\DynamicThrottle\Support\Explanation\ExplainedRoute;
use Afzal\DynamicThrottle\Support\Explanation\ExplainedRule;
use Afzal\DynamicThrottle\Support\Explanation\Explanation;
use Afzal\DynamicThrottle\Support\Explanation\ExplanationFailed;
use Afzal\DynamicThrottle\Support\Explanation\ExplanationRequest;
use Afzal\DynamicThrottle\Support\Explanation\RuleStatus;
use Afzal\DynamicThrottle\Support\ScaleFactor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Explains what the middleware does with a request, and why, without counting
 * it (SPEC-014, ADR-0008): the rules it matches, who the client counts as under
 * each, the counters, and the decision. It changes nothing.
 */
final class ExplainCommand extends Command
{
    protected $signature = 'dynamic-throttle:explain
        {method : The request\'s method, such as GET}
        {path : The request\'s path, such as /api/orders}
        {--ip=127.0.0.1 : The client\'s IP, as the app sees it after its trusted proxies}
        {--user= : A user\'s id, looked up by the user provider of --guard}
        {--guard= : The guard that looks the user up, the default guard without it}
        {--header=* : A header, as "Name: value", for header, api_token and custom scopes}
        {--json : Print the explanation as JSON}';

    protected $description = 'Explain which rules apply to a request, and what the next one gets';

    public function handle(): int
    {
        try {
            $explanation = $this->laravel->make(Explainer::class)->explain(ExplanationRequest::of(
                $this->text($this->argument('method')),
                $this->text($this->argument('path')),
                $this->text($this->option('ip')),
                $this->optional('user'),
                $this->optional('guard'),
                array_values(array_map($this->text(...), (array) $this->option('header'))),
            ));
        } catch (InvalidArgumentException|ExplanationFailed $exception) {
            return $this->failWith($exception->getMessage());
        } catch (Throwable $exception) {
            return $this->failWith('The request cannot be explained: '.$exception->getMessage());
        }

        if ($this->option('json') === true) {
            $this->raw($this->json($explanation));

            return self::SUCCESS;
        }

        $this->render($explanation);

        return self::SUCCESS;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function optional(string $option): ?string
    {
        $value = $this->option($option);

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    /**
     * An error, as one JSON object under --json, so a script always gets JSON (decision 6).
     */
    private function failWith(string $message): int
    {
        if ($this->option('json') === true) {
            $this->raw($this->json(['error' => $message]));
        } else {
            $this->error(Printable::text($message));
        }

        return self::FAILURE;
    }

    /**
     * Written as is: the console formatter would read a rule's name such as "<fg=red>"
     * as a style.
     */
    private function raw(string $line): void
    {
        $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
    }

    /**
     * @param  Explanation|array<string, string>  $value
     */
    private function json(Explanation|array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function render(Explanation $explanation): void
    {
        $this->line($this->requestLine($explanation));
        $this->line("Manifest: version {$explanation->manifest->version}, ".$this->counted(count($explanation->manifest->rules()), 'rule').', '.$this->counted(count($explanation->manifest->exemptions->all()), 'exemption'));
        $this->settings($explanation->settings);
        $this->route($explanation->route, $explanation->global);

        foreach ($explanation->rules as $rule) {
            $this->newLine();
            $this->rule($rule, $explanation);
        }

        $this->newLine();

        if ($explanation->countersError !== null) {
            $this->line('Counters: '.Printable::text($explanation->countersError));
        }

        $this->line('Decision: '.$this->decision($explanation->decision, $explanation));
    }

    private function requestLine(Explanation $explanation): string
    {
        $line = "Request: {$explanation->request->method} ".Printable::text($explanation->path).' from '.$explanation->request->ip;

        if ($explanation->request->user !== null) {
            $line .= ', as user '.Printable::text($explanation->request->user).' of guard '.Printable::text((string) $explanation->guard);
        }

        $headers = $explanation->request->headerNames();

        return $headers === [] ? $line : $line.', with the headers '.Printable::text(implode(', ', $headers));
    }

    private function settings(ManifestSettings $settings): void
    {
        if ($settings->bypass) {
            $this->line('Bypass: '.$this->until('on', $settings->bypassUntil));
        }

        if ($settings->scale !== null) {
            $this->line('Scale: '.$this->until('factor '.ScaleFactor::format($settings->scale), $settings->scaleUntil));
        }
    }

    private function until(string $setting, ?int $until): string
    {
        return $until === null ? "{$setting}, with no end time" : "{$setting} until {$this->time($until)}";
    }

    private function time(int $timestamp): string
    {
        return Date::createFromTimestamp($timestamp)->setTimezone(Date::now()->getTimezone())->format('Y-m-d H:i:s T');
    }

    private function route(?ExplainedRoute $route, bool $global): void
    {
        if (! $route instanceof ExplainedRoute) {
            $this->line('Route: none matches'.($global ? ', and dynamic.throttle runs as global middleware' : ''));

            return;
        }

        $this->line('Route: '.Printable::text($route->uri).($route->name !== null ? ', named '.Printable::text($route->name) : ''));
        $this->line('Middleware, in running order: '.($route->middleware === [] ? 'none' : Printable::text(implode(', ', $route->middleware))));

        if ($route->afterAuth === true) {
            $this->line('dynamic.throttle runs after authentication');
        } elseif ($route->afterAuth === false) {
            $this->line('No authentication middleware runs before dynamic.throttle: user rules count this route\'s clients as guests');
        }
    }

    private function rule(ExplainedRule $explained, Explanation $explanation): void
    {
        $rule = $explained->rule;
        $this->line("Rule {$rule->id}, ".Printable::text($rule->name).': '.$this->status($explained, $explanation));
        $this->line("  Matches: {$rule->method->value} ".Printable::text($rule->pattern).", priority {$rule->priority}");

        $scaled = $explanation->settings->scale !== null && $explained->limit !== $rule->maxAttempts
            ? " ({$rule->maxAttempts} × ".ScaleFactor::format($explanation->settings->scale).')'
            : '';
        $mode = $rule->mode === RuleMode::Enforce ? 'enforce' : 'dry run';
        $this->line("  Limit: {$explained->limit} per {$rule->windowSeconds} s{$scaled}, {$mode}, on failure {$rule->onFailure->value}");
        $this->line('  Scope: '.$rule->scope->value.($rule->scopeParameter !== null ? ':'.Printable::text($rule->scopeParameter) : ''));

        if ($explained->identity !== null) {
            $this->line('  Identity: '.Printable::text($explained->identity));
        }

        if ($explained->hash !== null) {
            $this->line("  Hash: {$explained->hash}");
        }

        if ($explained->status !== RuleStatus::Failed && $explained->error !== null) {
            $this->line('  Exemptions not checked: '.Printable::text($explained->error));
        }

        if ($explained->counter instanceof CounterReading) {
            $this->line('  Counter: '.$this->counter($explained->counter));
        }
    }

    private function status(ExplainedRule $rule, Explanation $explanation): string
    {
        return match ($rule->status) {
            RuleStatus::Counts => 'counts the request',
            RuleStatus::Exempt => 'exempt, by exemption '.Printable::text((string) $rule->exemption),
            RuleStatus::Skipped => 'skipped: the request has no identity for it',
            RuleStatus::Ignored => 'ignored: over the cap of '.$explanation->maxRulesPerRequest.' '.($rule->rule->mode === RuleMode::Enforce ? 'enforcing' : 'dry-run').' '.Str::plural('rule', $explanation->maxRulesPerRequest).' per request',
            RuleStatus::Failed => 'failed: '.Printable::text((string) $rule->error),
            RuleStatus::NotReached => 'not reached: rule '.Printable::text((string) $rule->stoppedBy).' stopped evaluation',
        };
    }

    private function counter(CounterReading $counter): string
    {
        $line = "{$counter->count} counted, {$counter->remaining()} remaining: the next request is ";

        return $counter->next->allowed ? $line.'allowed' : $line."limited, Retry-After {$counter->next->retryAfter} s";
    }

    private function decision(ExplainedDecision $decision, Explanation $explanation): string
    {
        $rule = Printable::text((string) $decision->rule);

        return match ($decision->outcome) {
            DecisionOutcome::Allowed => 'allowed'.$this->headers($decision->headers),
            DecisionOutcome::Rejected => "rejected by rule {$rule}: 429, Retry-After ".($decision->headers['Retry-After'] ?? 0).' s',
            DecisionOutcome::Unavailable => "unavailable by rule {$rule}: 503, Retry-After ".($decision->headers['Retry-After'] ?? 0).' s',
            DecisionOutcome::Failed => "failed: rule {$rule} could not be checked, and the request goes through".$this->headers($decision->headers),
            DecisionOutcome::Unknown => 'unknown: the counters cannot be read',
            DecisionOutcome::Untouched => 'untouched: '.$this->untouched($decision->untouched, $explanation),
        };
    }

    /**
     * @param  array<string, int>  $headers
     */
    private function headers(array $headers): string
    {
        if ($headers === []) {
            return '';
        }

        $named = [];

        foreach ($headers as $name => $value) {
            $named[] = "{$name} {$value}";
        }

        return ', with '.implode(' and ', $named);
    }

    private function untouched(?Untouched $untouched, Explanation $explanation): string
    {
        return match ($untouched?->reason) {
            UntouchedReason::NotAttached => $explanation->route instanceof ExplainedRoute
                ? 'the route does not run dynamic.throttle'
                : 'no route matches, and dynamic.throttle is not global middleware',
            UntouchedReason::Disabled => 'dynamic-throttle.enabled is off',
            UntouchedReason::OwnRoute => 'the route is one of the package\'s own',
            UntouchedReason::Except => 'except lists '.Printable::text((string) $untouched->pattern),
            UntouchedReason::Bypass => $this->until('bypass is on', $explanation->settings->bypassUntil),
            UntouchedReason::NoRule => 'no rule matches',
            null => 'nothing applies',
        };
    }

    private function counted(int $count, string $noun): string
    {
        return $count.' '.Str::plural($noun, $count);
    }
}
