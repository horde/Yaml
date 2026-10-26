<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\MapStyle;
use Horde\Yaml\Document\Node\Node;
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\Node\SequenceStyle;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block sequence's items are emitted as faithfully as a mapping's values.
 *
 * The sequence-item path did not share the mapping-entry path, and handled
 * fewer cases. A flow collection as an item, or as the value of an item's
 * first key, was written after the line break, at column 0 of the next line.
 * An empty collection as an item was dropped, so the item read back as null.
 * Tags and anchors on items were dropped, so `- !!str 1` read back as an
 * integer. An alias as an item threw. A block scalar as an item gained a
 * blank line.
 * @coversNothing
 */
final class SequenceItemEmitTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function documents(): array
    {
        return [
            'empty flow sequence'          => ["a:\n  - []\nb: 1\n"],
            'empty flow mapping'           => ["a:\n  - {}\nb: 1\n"],
            'flow sequence'                => ["a:\n  - [1, 2]\n  - {x: 1}\nb: 1\n"],
            'at the root'                  => ["- []\n- {}\n"],
            'nested in a sequence'         => ["-\n  - [1]\n"],
            'first key, flow mapping'      => ["- a: {x: 1}\n  b: 2\n"],
            'first key, empty sequence'    => ["- a: []\n"],
            'first key, flow sequence'     => ["- a: [1]\n  b: 2\n"],
            'alias'                        => ["- &x a\n- *x\n"],
            'first key, alias'             => ["- &x a\n- a: *x\n"],
            'tag'                          => ["- !!str 1\n"],
            'first key, tag'               => ["- a: !!str 1\n"],
            'first key, anchor'            => ["- a: &y 1\n"],
            'anchored flow mapping'        => ["- &m {a: 1}\n"],
            'anchored block mapping'       => ["- &m\n  a: 1\n"],
            'literal block'                => ["- |\n  text\n- x\n"],
            'first key, literal block'     => ["- a: |\n    t\n  b: 1\n"],
            'first key, nested mapping'    => ["- a:\n    b: 1\n  c: 2\n"],
        ];
    }

    /**
     * A comment before an item's first key was taken for the first key: the
     * first entry was written twice and the comment dropped. It is now the
     * item's end-of-line comment, on the dash's line where it was written.
     */
    public function testACommentBeforeTheFirstKeyIsNeitherLostNorDuplicated(): void
    {
        self::assertSame(
            "- # note\n  a: 1\n  b: 2\n",
            (new Emitter())->emit((new YamlStringLoader())->load("- # note\n  a: 1\n  b: 2\n")),
        );
    }

    #[DataProvider('documents')]
    public function testASequenceItemRoundTripsByteForByte(string $source): void
    {
        self::assertSame($source, (new Emitter())->emit((new YamlStringLoader())->load($source)));
    }

    /** @return array<string, array{callable(): Node, mixed}> */
    public static function emptyCollections(): array
    {
        return [
            'block sequence' => [fn() => new SequenceNode(), []],
            'block mapping'  => [fn() => new MapNode(), []],
            'flow sequence'  => [fn() => new SequenceNode(SequenceStyle::Flow), []],
            'flow mapping'   => [fn() => new MapNode(MapStyle::Flow), []],
        ];
    }

    /**
     * An empty collection has no block form. A block-styled one, which is
     * what `new MapNode()` and `new SequenceNode()` create, was emitted as
     * nothing and read back as null, as a map value as well as an item.
     *
     * @param callable(): Node $node
     */
    #[DataProvider('emptyCollections')]
    public function testAnEmptyCollectionIsNotReadBackAsNull(callable $node, mixed $expected): void
    {
        $stream = (new YamlStringLoader())->load("value: x\nitems:\n  - x\nafter: 1\n");
        $root = $stream->getDocument()->root();
        $root->setEntry('value', $node());
        $root->entry('items')->getValue()->setItemAt(0, $node());

        $emitted = (new Emitter())->emit($stream);

        self::assertSame(
            ['value' => $expected, 'items' => [$expected], 'after' => 1],
            (new YamlStringLoader())->load($emitted)->getDocument()->root()->resolved(),
            $emitted,
        );
    }
}
