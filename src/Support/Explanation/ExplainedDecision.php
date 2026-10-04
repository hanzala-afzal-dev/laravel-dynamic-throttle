<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Explanation;

use Afzal\DynamicThrottle\Http\Untouched;
use JsonSerializable;
use stdClass;

/**
 * What the next request gets, and from which rule (SPEC-014).
 *
 * @internal
 */
final readonly class ExplainedDecision implements JsonSerializable
{
    /**
     * @param  ?Untouched  $untouched  the step that lets the request through, for DecisionOutcome::Untouched
     * @param  ?string  $rule  the deciding rule's id: the 429's, the 503's, or the first that failed
     * @param  array<string, int>  $headers  the rate-limit headers the next request gets
     */
    public function __construct(
        public DecisionOutcome $outcome,
        public ?Untouched $untouched = null,
        public ?string $rule = null,
        public array $headers = [],
    ) {}

    /**
     * Headers as an object, {} when there are none.
     *
     * @return array{outcome: string, reason: ?string, rule: ?string, headers: array<string, int>|stdClass}
     */
    public function jsonSerialize(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'reason' => $this->untouched?->reason->value,
            'rule' => $this->rule,
            'headers' => $this->headers === [] ? new stdClass : $this->headers,
        ];
    }
}
