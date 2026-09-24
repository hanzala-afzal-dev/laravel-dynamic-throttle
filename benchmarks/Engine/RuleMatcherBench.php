<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Benchmarks\Engine;

use Afzal\DynamicThrottle\Engine\Matching\RuleIndex;
use Afzal\DynamicThrottle\Engine\Matching\RuleMatcher;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;
use PhpBench\Attributes as Bench;
use RuntimeException;

/**
 * SPEC-007 AC-19, ADR-0008's verification: matching one request against 100
 * or 500 active rules, 3 of which match. benchBuildIndex is what ADR-0023
 * adds to every manifest load, and benchDecode is a whole load (decoding
 * and validating every rule, then building the index), for comparison.
 */
#[Bench\Revs(1_000)]
#[Bench\Iterations(10)]
#[Bench\Warmup(1)]
#[Bench\BeforeMethods('setUp')]
#[Bench\ParamProviders('sizes')]
final class RuleMatcherBench
{
    /** The request every subject matches. */
    private const string METHOD = 'GET';

    private const string PATH = 'api/users/42/export';

    private RuleMatcher $matcher;

    private Manifest $manifest;

    /** @var list<CompiledRule> */
    private array $rules;

    private string $json;

    /**
     * @return iterable<string, array{rules: int}>
     */
    public function sizes(): iterable
    {
        yield '100 rules' => ['rules' => 100];
        yield '500 rules' => ['rules' => 500];
    }

    /**
     * @param  array{rules: int}  $params
     */
    public function setUp(array $params): void
    {
        $this->rules = self::rules($params['rules']);
        $this->manifest = new Manifest(1, $this->rules);
        $this->json = ManifestCodec::encode(1, $this->rules);
        $this->matcher = new RuleMatcher;

        if (count($this->matcher->match($this->manifest, self::METHOD, self::PATH)->rules) !== 3) {
            throw new RuntimeException('The benchmark request must match exactly 3 rules.');
        }
    }

    public function benchMatch(): void
    {
        $this->matcher->match($this->manifest, self::METHOD, self::PATH);
    }

    #[Bench\Revs(100)]
    public function benchBuildIndex(): void
    {
        RuleIndex::of($this->rules);
    }

    #[Bench\Revs(100)]
    public function benchDecode(): void
    {
        ManifestCodec::decode($this->json);
    }

    /**
     * Three rules that match the request, four more that it tests without a
     * match, and other services' rules that the index never offers it.
     *
     * @return list<CompiledRule>
     */
    private static function rules(int $count): array
    {
        $rules = [
            ['*', 'api/*'],
            ['GET', 'api/users/*'],
            ['*', '*/export'],
            ['GET', 'api/orders/*'],
            ['*', 'api/users'],
            ['*', '*/admin/*'],
            ['GET', '*.php'],
            ['POST', 'api/users/*'],
        ];

        for ($id = count($rules) + 1; $id <= $count; $id++) {
            $rules[] = [['*', 'GET', 'POST'][$id % 3], "service-{$id}/*"];
        }

        return array_map(
            fn (array $rule, int $position): CompiledRule => CompiledRule::fromArray(ManifestFixtures::rule([
                'id' => (string) ($position + 1),
                'method' => $rule[0],
                'pattern' => $rule[1],
            ])),
            $rules,
            array_keys($rules),
        );
    }
}
