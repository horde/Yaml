<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\YamlStringLoader;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What was written comes back as written.
 *
 * - The spaces before a key's colon (`key : value`) were dropped.
 * - An empty node was written as `null`: `a:` came back as `a: null`, an
 *   empty key as `null:`, and `a: !!null` as `a: !!null null`.
 * - A key ignored its raw source, so `0x1F: a` came back as `31: a`.
 * - A quoted scalar spanning lines was folded onto one.
 * - A block scalar's indentation indicator was written relative to an
 *   indent of 2, adding a space to every line of `>1` content.
 * - A blank line after a flow collection value was dropped: the newline
 *   after the closing bracket was consumed twice.
 * @coversNothing
 */
final class LayoutFidelityTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function documents(): array
    {
        return [
            'space before colon'      => ["b : x\n"],
            'tab before colon'        => ["a\t: 1\n"],
            'quoted key, space'       => ["\"top1\" :\n  \"key1\" : v\n"],
            'item keys, spaces'       => ["- a : 1\n  b  : 2\n"],
            'flow key, space'         => ["[x] : y\n"],
            'alias key, wide gap'     => ["&a a: 1\n*a   : x\n"],
            'empty value'             => ["a:\nb: 1\n"],
            'empty item'              => ["-\n- x\n"],
            'empty key'               => [": a\n"],
            'empty key and value'     => ["&c : &a\n"],
            'empty flow value'        => ["k: {a: }\n"],
            'anchored empty value'    => ["a: &x\nb: *x\n"],
            'tagged empty null'       => ["a: !!null\n"],
            'tagged empty string'     => ["a: !!str\n"],
            'hex key'                 => ["0x1F: a\n"],
            'tilde key'               => ["~: a\n"],
            'multi-line double'       => ["a: \"double\n  quotes\" # c\n"],
            'multi-line single'       => ["a: 'single\n  quotes'\n"],
            'multi-line in a flow'    => ["k: [\"a\n  b\", c]\n"],
            'indentation indicator'   => ["literal: |2\n  value\nfolded: !foo >1\n value\n"],
            'nested indicator'        => ["k:\n  a: >2\n     x\n"],
            'blank after flow value'  => ["a:\n  b: []\n\nc: 1\n"],
            'blank after flow item'   => ["- &a [x]\n\n- y\n"],
            'blank after commented'   => ["a: [x] # c\n\nb: 1\n"],
        ];
    }

    #[DataProvider('documents')]
    public function testTheDocumentRoundTripsByteForByte(string $source): void
    {
        self::assertSame($source, (new Emitter())->emit((new YamlStringLoader())->load($source)));
    }

    /** The negative control: a `null` that was written stays `null`. */
    public function testAWrittenNullIsStillWritten(): void
    {
        self::assertSame("a: null\n", (new Emitter())->emit((new YamlStringLoader())->load("a: null\n")));
    }

    /** An entry built in code has no gap, and a null value is `null`. */
    public function testAnEntryBuiltInCodeIsWrittenCanonically(): void
    {
        $stream = (new YamlStringLoader())->load("x: 1\n");
        $stream->getDocument()->root()->setEntry('y', null);

        self::assertSame("x: 1\ny: null\n", (new Emitter())->emit($stream));
    }

    public function testAColonGapHoldsOnlyWhitespace(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MapEntry(new ScalarNode('k'), new ScalarNode('v')))->setColonGap(' x');
    }
}
