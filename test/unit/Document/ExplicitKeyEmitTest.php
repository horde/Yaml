<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\Node\ChompMode;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\Node;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Explicit keys (`? key` / `: value`, YAML 1.2 §8.2.2) are emitted as such.
 *
 * The emitter had no explicit form. It wrote every key as an implicit one,
 * so a block scalar key, a block collection key, or a key spanning lines came
 * back as a document that did not load, and a compound key was folded onto
 * one line. An explicit key is now written back as one, and a key that
 * cannot be implicit is written in explicit form whatever its source.
 * @coversNothing
 */
final class ExplicitKeyEmitTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function documents(): array
    {
        return [
            'scalar key and value'      => ["? a\n: b\n"],
            'no value line'             => ["? a\n? b\n"],
            'empty key'                 => ["?\n: x\n"],
            'empty value line'          => ["? >\n  a\n:\n"],
            'block scalar key'          => ["? |\n  block key\n: - one\n  - two\n"],
            'sequence key, compact'     => ["? - a\n  - b\n: c\n"],
            'sequence key, same indent' => ["?\n- a\n- b\n:\n- c\n- d\n"],
            'sequence value, compact'   => ["? k\n: - x\n  - y\n"],
            'sequence value, own line'  => ["? k\n:\n  - x\n"],
            'mapping key'               => ["? earth: blue\n: moon: white\n"],
            'multi-line plain key'      => ["? a\n  true\n: null\n  d\n? e\n  42\n"],
            'multi-line flow key'       => ["? [ a,\n    b ]\n: [ c,\n    d ]\n"],
            'nested'                    => ["complex:\n  ? - a\n  : - b\n"],
            'blank line between'        => ["? - a\n:\n  - b\n\n? c\n: d\n"],
            // A compact mapping of more than one entry did not parse: only
            // its first entry was scanned.
            'mapping key, two entries'  => ["? a: 1\n  b: 2\n: 1\n"],
            'mapping value, two'        => ["? a: 1\n: b: 2\n  c: 3\n"],
            'nested mapping key'        => ["k:\n  ? a: 1\n    b: 2\n  : v\n"],
            'anchored first key'        => ["? &k earth: blue\n: moon\n"],
        ];
    }

    #[DataProvider('documents')]
    public function testAnExplicitEntryRoundTripsByteForByte(string $source): void
    {
        self::assertSame($source, (new Emitter())->emit((new YamlStringLoader())->load($source)));
    }

    /** @return array<string, array{callable(): Node}> */
    public static function keysThatCannotBeImplicit(): array
    {
        return [
            'block scalar'     => [fn() => new ScalarNode("block\n", ScalarStyle::LiteralBlock, chomp: ChompMode::Clip)],
            'multi-line text'  => [fn() => new ScalarNode("two\nlines\n")],
            'block mapping'    => [fn() => self::mapping()],
            'block sequence'   => [fn() => self::sequence()],
            'over 1024 chars'  => [fn() => new ScalarNode(str_repeat('k', 1100))],
        ];
    }

    /**
     * A key built in code that an implicit key cannot hold is written in
     * explicit form, so the document loads and the key reads back whole.
     *
     * @param callable(): Node $key
     */
    #[DataProvider('keysThatCannotBeImplicit')]
    public function testAKeyThatCannotBeImplicitIsWrittenExplicitly(callable $key): void
    {
        $stream = (new YamlStringLoader())->load("x: 1\n");
        $map = $stream->getDocument()->root();
        $map->entries()[0]->setKey($key());

        $emitted = (new Emitter())->emit($stream);
        $reloaded = (new YamlStringLoader())->load($emitted)->getDocument()->root();

        self::assertStringStartsWith('?', $emitted);
        self::assertSame(self::describe($map->entries()[0]->getKey()), self::describe($reloaded->entries()[0]->getKey()), $emitted);
        self::assertSame(1, $reloaded->entries()[0]->getValue()->getValue());
    }

    /** The negative control: an ordinary key stays implicit. */
    public function testAnOrdinaryKeyStaysImplicit(): void
    {
        $stream = (new YamlStringLoader())->load("x: 1\n");
        $stream->getDocument()->root()->entries()[0]->setKey(new ScalarNode('plain'));

        self::assertSame("plain: 1\n", (new Emitter())->emit($stream));
    }

    private static function mapping(): MapNode
    {
        $map = new MapNode();
        $map->setEntry('a', 1);
        $map->setEntry('b', 2);
        return $map;
    }

    private static function sequence(): SequenceNode
    {
        $sequence = new SequenceNode();
        $sequence->appendItem('a');
        $sequence->appendItem('b');
        return $sequence;
    }

    private static function describe(Node $node): string
    {
        if ($node instanceof MapNode) {
            return json_encode($node->resolved(), JSON_THROW_ON_ERROR);
        }
        if ($node instanceof SequenceNode) {
            return json_encode(array_map(static fn($item) => $item->getValue()->getValue(), $node->items()), JSON_THROW_ON_ERROR);
        }
        assert($node instanceof ScalarNode);
        return json_encode($node->getValue(), JSON_THROW_ON_ERROR);
    }
}
