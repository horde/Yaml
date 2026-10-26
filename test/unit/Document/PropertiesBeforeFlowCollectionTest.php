<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\LeniencyPolicy;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\Node;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An anchor or tag before a flow collection applies to the collection.
 *
 * The scanner's look-ahead for a block-mapping key skipped the properties and
 * then found the `: ` inside the collection, so `- &m {a: 1}` was read as a
 * mapping from the string "&m {a" to the string "1}", and `&m {a: 1}` at the
 * root did not parse. Without the properties, the flow collection was already
 * recognised first.
 * @coversNothing
 */
final class PropertiesBeforeFlowCollectionTest extends TestCase
{
    /** @return array<string, array{string, mixed}> */
    public static function documents(): array
    {
        return [
            'anchored mapping item'   => ["- &m {a: 1}\n", [['a' => 1]]],
            'tagged mapping item'     => ["- !!map {a: 1}\n", [['a' => 1]]],
            'anchored sequence item'  => ["- &s [a: 1]\n", [[['a' => 1]]]],
            'anchored mapping root'   => ["&m {a: 1}\n", ['a' => 1]],
            'quoted brace inside'     => ["- &m {a: \"}: x\"}\n", [['a' => '}: x']]],
            // The negative controls: a flow collection followed by `:` is
            // still a key, with or without properties (yaml-test-suite 6BFJ).
            'anchored flow key'       => ["&key [a, b]: value\n", ['[<sequence key>]' => 'value']],
            'map value, unaffected'   => ["k: &m {a: 1}\n", ['k' => ['a' => 1]]],
        ];
    }

    #[DataProvider('documents')]
    public function testThePropertiesApplyToTheCollection(string $source, mixed $expected): void
    {
        $root = (new YamlStringLoader(policy: LeniencyPolicy::strictYaml12()))->load($source)->getDocument()->root();

        self::assertSame($expected, self::value($root));
    }

    private static function value(?Node $node): mixed
    {
        if ($node instanceof MapNode) {
            return $node->resolved();
        }
        if ($node instanceof SequenceNode) {
            return array_map(static fn($item) => self::value($item->getValue()), $node->items());
        }
        return $node instanceof ScalarNode ? $node->getValue() : null;
    }
}
