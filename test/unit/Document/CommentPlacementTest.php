<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An end-of-line comment stays on its line.
 *
 * Comments were dropped after a flow collection value (`k: [x] # c`), after
 * an empty value (`a: # c`), after `?` and an explicit `:`, after an explicit
 * key, after properties in a sequence item (`- &a # c`), and before a value
 * written on the next line. Others moved to a line of their own: after an
 * empty sequence item (`- # c`), after properties before a nested block
 * (`e: &node # c`), and before an item's first key (`- # c\n  a: 1`).
 * @coversNothing
 */
final class CommentPlacementTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function documents(): array
    {
        return [
            'flow sequence value'         => ["k: [x] # c\nz: 1\n"],
            'flow mapping value'          => ["k: {a: 1}  # c\n"],
            'flow item'                   => ["- [x] # c\n- {a: 1}  # d\n"],
            'multi-line flow value'       => ["k: [\n  x\n] # c\n"],
            'anchored flow value'         => ["k: &a [x] # c\n"],
            'empty value'                 => ["a: # c\nb: 1\n"],
            'empty value, wide gap'       => ["a:   # c\n"],
            'empty item'                  => ["- # c\n- x\n"],
            'empty item, wide gap'        => ["-   # c\n"],
            'explicit key and value'      => ["? k # c1\n: v # c2\n"],
            'after ? and :'               => ["? # c1\n - a\n: # c2\n - b\n"],
            'explicit empty key, value'   => ["? # c\n: # d\n"],
            'properties, nested mapping'  => ["e: &node # c\n - x: y\n"],
            'tag, nested sequence'        => ["e: !!seq # c\n  - x\n"],
            'item properties'             => ["- &a # c\n  a: 1\n"],
            'before the first key'        => ["- # c\n  a: 1\n  b: 2\n"],
            'nested sequence item'        => ["- # c\n  - x\n"],
        ];
    }

    #[DataProvider('documents')]
    public function testTheCommentStaysOnItsLine(string $source): void
    {
        self::assertSame($source, (new Emitter())->emit((new YamlStringLoader())->load($source)));
    }

    /** @return array<string, array{string, string}> */
    public static function valueOnTheNextLine(): array
    {
        return [
            'mapping value'  => ["c  : #lala\n  d\n", "c  : d #lala\n"],
            'sequence item'  => ["- #lala\n  seq2\n", "- seq2 #lala\n"],
        ];
    }

    /**
     * A comment before a scalar written on the next line was dropped. It is
     * kept as the entry's end-of-line comment, after the value.
     */
    #[DataProvider('valueOnTheNextLine')]
    public function testACommentBeforeAValueOnTheNextLineIsKept(string $source, string $expected): void
    {
        self::assertSame($expected, (new Emitter())->emit((new YamlStringLoader())->load($source)));
    }
}
