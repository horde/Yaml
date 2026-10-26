<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\LeniencyPolicy;
use Horde\Yaml\Document\ParseException;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A mapping's keys are unique (YAML 1.2 §3.2.1.1).
 *
 * The loader accepted a repeated key under every policy, and resolved() kept
 * the last value, so a document that silently lost an entry loaded as if
 * nothing were wrong. DuplicateKeyException existed, but only addEntry()
 * threw it. A repeated key is now refused unless acceptDuplicateMappingKeys
 * is on, as it is in hordeCompat().
 * @coversNothing
 */
final class DuplicateMappingKeyTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function duplicates(): array
    {
        return [
            'plain'              => ["a: 1\na: 2\n"],
            'nested'             => ["x:\n  a: 1\n  b: 2\n  a: 3\n"],
            'quoted and plain'   => ["a: 1\n\"a\": 2\n"],
            'same integer'       => ["1: a\n0x1: b\n"],
            'flow'               => ["{a: 1, a: 2}\n"],
            'in a sequence item' => ["- a: 1\n  a: 2\n"],
        ];
    }

    #[DataProvider('duplicates')]
    public function testARepeatedKeyIsRefusedUnderStrictYaml12(string $source): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Duplicate mapping key');

        (new YamlStringLoader(policy: LeniencyPolicy::strictYaml12()))->load($source);
    }

    #[DataProvider('duplicates')]
    public function testARepeatedKeyIsAcceptedWhenTheFlagIsOn(string $source): void
    {
        $policy = LeniencyPolicy::strictYaml12()->with(['acceptDuplicateMappingKeys' => true]);

        self::assertSame(1, (new YamlStringLoader(policy: $policy))->load($source)->documentCount());
    }

    /** The default loader keeps its behaviour: the last value wins. */
    public function testTheDefaultPolicyStillAcceptsARepeatedKey(): void
    {
        self::assertSame(['a' => 2], (new YamlStringLoader())->load("a: 1\na: 2\n")->getDocument()->root()->resolved());
    }

    /** @return array<string, array{string}> */
    public static function distinct(): array
    {
        return [
            'integer and string' => ["1: a\n\"1\": b\n"],
            'boolean and string' => ["true: a\n\"true\": b\n"],
            'same key, two maps' => ["x:\n  a: 1\ny:\n  a: 1\n"],
            'merge keys'         => ["b: &b {x: 1}\nc: &c {y: 1}\nd:\n  <<: *b\n  <<: *c\n"],
        ];
    }

    /**
     * The negative control: keys that resolve to different values, or that
     * sit in different mappings, are not duplicates.
     */
    #[DataProvider('distinct')]
    public function testDistinctKeysAreAccepted(string $source): void
    {
        self::assertSame(1, (new YamlStringLoader(policy: LeniencyPolicy::strictYaml12()))->load($source)->documentCount());
    }
}
