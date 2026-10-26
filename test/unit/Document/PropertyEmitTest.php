<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Anchors and tags are emitted wherever they were read.
 *
 * The emitter dropped them on mapping keys, on values inside flow
 * collections, and on a root collection, and wrote "?" for anything but a
 * scalar inside a compound key. A dropped anchor is not cosmetic: an alias
 * after it no longer resolves, so `- &a a: b\n- *a` came back as a document
 * that does not load.
 *
 * An alias key was written `*a: b`. An anchor name may contain ":", so that
 * is the alias "a:", and the key read back as a different alias. And an
 * end-of-line comment after an alias value was moved to a line of its own,
 * or, in a sequence item, dropped.
 * @coversNothing
 */
final class PropertyEmitTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function documents(): array
    {
        return [
            'anchored key in an item'  => ["- &a a: b\n- *a\n"],
            'tagged key in an item'    => ["- !!str a: b\n"],
            'anchored flow key'        => ["- &k [x]: y\n"],
            'anchored key in a map'    => ["m:\n  &k a: b\n"],
            'flow sequence items'      => ["k: [&a x, *a]\n"],
            'flow mapping key, value'  => ["k: {&a a: &b 1, c: *b}\n"],
            'tagged flow item'         => ["k: [!!str 1]\n"],
            'anchored flow collection' => ["k: [&s [1], *s]\n"],
            'compound key with alias'  => ["[&a x, *a]: y\n"],
            'root flow collection'     => ["&r [1, 2]\n"],
            'root block mapping'       => ["!!map\na: 1\n"],
            'alias key'                => ["&a a: &b b\n*b : *a\n"],
            'alias key in an item'     => ["- &a a: 1\n- *a : x\n  y: 2\n"],
            'alias key in flow'        => ["k: {&a a: 1, *a : 2}\n"],
            'alias value, comment'     => ["&a a: 1\nb: *a # c\n"],
            'alias item, comment'      => ["- &a x\n- *a # c\n- y\n"],
            'alias after empty key'    => ["&a a: &b b\n*b : *a # c\nz: 1\n"],
        ];
    }

    #[DataProvider('documents')]
    public function testThePropertiesRoundTripByteForByte(string $source): void
    {
        self::assertSame($source, (new Emitter())->emit((new YamlStringLoader())->load($source)));
    }
}
