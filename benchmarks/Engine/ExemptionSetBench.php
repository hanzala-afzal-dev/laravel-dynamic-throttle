<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Benchmarks\Engine;

use Afzal\DynamicThrottle\Engine\Contracts\ExemptionSubjects;
use Afzal\DynamicThrottle\Engine\Exemptions\ExemptionSet;
use Afzal\DynamicThrottle\Manifest\CompiledExemption;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;
use PhpBench\Attributes as Bench;
use RuntimeException;

/**
 * SPEC-009 AC-23: what exemptions cost. benchDecode is a whole manifest load, with
 * 100 rules and none, 100 or 1,000 exemptions. benchCheck is one rule's check for a
 * request that no exemption frees, so every subject is asked and every prefix length
 * looked up: the most a check costs. Its subjects arrive hashed, as the resolver
 * gives them after hashing each value once per request.
 */
#[Bench\Revs(1_000)]
#[Bench\Iterations(10)]
#[Bench\Warmup(1)]
#[Bench\BeforeMethods('setUp')]
#[Bench\ParamProviders('sizes')]
final class ExemptionSetBench
{
    /** Unix microseconds: no exemption here expires. */
    private const int NOW = 1_800_000_000_000_000;

    private string $json;

    private ExemptionSet $set;

    private ExemptionSubjects $subjects;

    /**
     * @return iterable<string, array{exemptions: int}>
     */
    public function sizes(): iterable
    {
        yield 'no exemptions' => ['exemptions' => 0];
        yield '100 exemptions' => ['exemptions' => 100];
        yield '1,000 exemptions' => ['exemptions' => 1_000];
    }

    /**
     * @param  array{exemptions: int}  $params
     */
    public function setUp(array $params): void
    {
        $rules = array_map(
            fn (int $id): CompiledRule => CompiledRule::fromArray(ManifestFixtures::rule(['id' => (string) $id, 'pattern' => "service-{$id}/*"])),
            range(1, 100),
        );
        $exemptions = [];

        for ($n = 0; $n < $params['exemptions']; $n++) {
            $exemptions[] = $this->exemption($n, $params['exemptions']);
        }

        $this->json = ManifestCodec::encode(1, $rules, $exemptions);
        $this->set = new ExemptionSet($exemptions);
        $this->subjects = new class implements ExemptionSubjects
        {
            public function ip(): string
            {
                return '203.0.113.9';
            }

            public function user(): string
            {
                return hash('sha256', 'App\Models\User:999999');
            }

            public function custom(string $scope): string
            {
                return hash('sha256', 'initech');
            }
        };

        if ($this->set->match('1', self::NOW, $this->subjects) !== null) {
            throw new RuntimeException('No exemption may free the benchmark request.');
        }
    }

    #[Bench\Revs(100)]
    public function benchDecode(): void
    {
        ManifestCodec::decode($this->json);
    }

    public function benchCheck(): void
    {
        $this->set->match('1', self::NOW, $this->subjects);
    }

    /**
     * Half are ranges of four prefix lengths, /32 and /16 global, /24 and /8 of rule
     * 1. A third are rule 1's users, and the rest are global custom values.
     */
    private function exemption(int $n, int $count): CompiledExemption
    {
        $ranges = intdiv($count, 2);
        $users = intdiv($count, 3);
        $i = intdiv($n, 4) + 1;

        $fields = match (true) {
            $n < $ranges => [
                'rule' => $n % 2 === 0 ? null : '1',
                'match' => ["10.0.{$i}.1/32", "11.{$i}.0.0/24", "12.{$i}.0.0/16", "{$i}.0.0.0/8"][$n % 4],
            ],
            $n < $ranges + $users => ['rule' => '1', 'subject' => 'user', 'match' => hash('sha256', "App\\Models\\User:{$n}")],
            default => ['subject' => 'custom', 'parameter' => 'tenant', 'match' => hash('sha256', "tenant-{$n}")],
        };

        return CompiledExemption::fromArray(ManifestFixtures::exemption(['id' => (string) ($n + 1), ...$fields]));
    }
}
