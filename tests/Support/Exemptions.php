<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Compiler\ManifestCompiler;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Models\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Exemptions along their whole path (SPEC-009): rows in the table, the compiler,
 * the manifest in the cache, and one request's resolver.
 */
final class Exemptions
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function rule(array $overrides = []): Rule
    {
        return Rule::create(RuleSchema::attributes($overrides));
    }

    /**
     * An exemptions row written without Eloquent: it can hold values the model
     * would refuse, and writing it compiles nothing.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function insert(array $overrides = []): int
    {
        return (int) DB::table('dynamic_throttle_exemptions')->insertGetId([
            'rule_id' => null,
            'subject' => 'ip',
            'subject_parameter' => null,
            'value' => '203.0.113.7',
            'reason' => null,
            'expires_at' => null,
            ...$overrides,
        ]);
    }

    /**
     * The stored manifest's JSON, decoded.
     *
     * @return array<string, mixed>
     */
    public static function stored(): array
    {
        $data = json_decode(self::json(), true, flags: JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : throw new RuntimeException('The stored manifest is not a JSON object.');
    }

    public static function json(): string
    {
        $json = Cache::get(app(KeyBuilder::class)->manifest());

        return is_string($json) ? $json : throw new RuntimeException('No manifest is stored.');
    }

    /**
     * Compiles, then reads the manifest back as a request would.
     */
    public static function manifest(): Manifest
    {
        app(ManifestCompiler::class)->compile();

        return ManifestCodec::decode(self::json());
    }

    /**
     * The manifest's rule compiled from this row.
     */
    public static function compiled(Manifest $manifest, Rule $rule): CompiledRule
    {
        foreach ($manifest->rules() as $compiled) {
            if ($compiled->id === (string) $rule->id) {
                return $compiled;
            }
        }

        throw new RuntimeException("Rule {$rule->id} is not in the manifest.");
    }

    /**
     * The id of the exemption that frees the request from a rule, or null: compiles
     * first, and checks the manifest's first rule unless another one is given.
     */
    public static function check(Request $request, ?Rule $rule = null): ?string
    {
        $manifest = self::manifest();
        $compiled = $rule instanceof Rule ? self::compiled($manifest, $rule) : $manifest->rule(0);

        return Identities::resolver($request, $manifest->exemptions)->exemption($compiled);
    }
}
