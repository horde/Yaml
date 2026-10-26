<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\LeniencyPolicy;
use Horde\Yaml\Document\Node\AliasNode;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\Node;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An anchor or tag belongs to the node it precedes.
 *
 * Several scanner paths left properties to consumeScalar(), which read them
 * as part of the text: in a sequence item (`- &k a: b` had the key "&k a"),
 * in an explicit key or value (`? &a a`), and before an empty value
 * (`&c : &a` had the value "&a"). An alias was read as text in the same
 * places: `*b : *a` had the value "*a", and `- *a : x` the key "*a". A
 * property on the same line as the first
 * key of a block mapping went to the mapping, not the key (yaml-test-suite
 * U3XV `top3`). And a multi-byte anchor or tag name kept only its first byte.
 *
 * Each expectation is written as the node tree with `&anchor` and `!tag`
 * prefixes, as yaml-test-suite's event streams place them.
 * @coversNothing
 */
final class PropertyPlacementTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function documents(): array
    {
        return [
            'item, plain key'          => ["- &k a: b\n", '[{&k"a":"b"}]'],
            'item, tagged key'         => ["- !!str a: b\n", '[{!!str"a":"b"}]'],
            'item, key then sibling'   => ["- &k a: b\n  c: d\n", '[{&k"a":"b","c":"d"}]'],
            'item, flow key'           => ["- &k [x]: y\n", '[{&k["x"]:"y"}]'],
            'item, flow key, two'      => ["- !t &k [x, y]: z\n", '[{&k!t["x","y"]:"z"}]'],
            'item, flow mapping key'   => ["- &m {a: 1}: x\n", '[{&m{"a":1}:"x"}]'],
            'item, alias of the key'   => ["- &a a: b\n- *a\n", '[{&a"a":"b"},*a]'],
            'nested, same line'        => ["m:\n  &k a: b\n", '{"m":{&k"a":"b"}}'],
            'nested, own line'         => ["m:\n  &n\n  a: b\n", '{"m":&n{"a":"b"}}'],
            'root, same line'          => ["&k a: b\n", '{&k"a":"b"}'],
            'root, own line'           => ["&m\n&k a: b\n", '&m{&k"a":"b"}'],
            'explicit key and value'   => ["? &a a\n: &b b\n", '{&a"a":&b"b"}'],
            'explicit empty key'       => ["? &d\n: x\n", '{&dnull:"x"}'],
            'explicit inline mapping'  => ["? &k earth: blue\n: moon\n", '{{&k"earth":"blue"}:"moon"}'],
            'empty value'              => ["&c : &a\n", '{&cnull:&anull}'],
            'alias key, alias value'   => ["&a a: &b b\n*b : *a\n", '{&a"a":&b"b",*b:*a}'],
            'item, alias key'          => ["- &a a: 1\n- *a : x\n", '[{&a"a":1},{*a:"x"}]'],
            'multibyte anchor'         => ["- &😁 x\n- *😁\n", '[&😁"x",*😁]'],
            'multibyte tag'            => ["- !ünï y\n", '[!ünï"y"]'],
        ];
    }

    #[DataProvider('documents')]
    public function testThePropertiesBelongToTheNodeTheyPrecede(string $source, string $expected): void
    {
        $root = (new YamlStringLoader(policy: LeniencyPolicy::strictYaml12()))->load($source)->getDocument()->root();

        self::assertSame($expected, self::describe($root));
    }

    private static function describe(?Node $node): string
    {
        if ($node === null) {
            return 'null';
        }
        if ($node instanceof AliasNode) {
            return '*' . $node->getTargetName();
        }
        $properties = ($node->getAnchor() !== null ? '&' . $node->getAnchor() : '')
            . ($node->getTag() ?? '');
        if ($node instanceof MapNode) {
            $entries = array_map(
                static fn($entry) => self::describe($entry->getKey()) . ':' . self::describe($entry->getValue()),
                $node->entries(),
            );
            return $properties . '{' . implode(',', $entries) . '}';
        }
        if ($node instanceof SequenceNode) {
            $items = array_map(static fn($item) => self::describe($item->getValue()), $node->items());
            return $properties . '[' . implode(',', $items) . ']';
        }
        assert($node instanceof ScalarNode);
        return $properties . json_encode($node->getValue(), JSON_UNESCAPED_UNICODE);
    }
}
