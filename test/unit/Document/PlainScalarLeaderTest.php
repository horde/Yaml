<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "?", ":" and "-" may begin a plain scalar.
 *
 * YAML 1.2 ns-plain-first admits them when they are followed by a plain-safe
 * character; they are indicators only when they stand alone or precede a space.
 * The emitter treated them as unconditionally reserved, so a command-line flag
 * such as `--cert-dir=/tmp` came back quoted - a change to a line the author
 * did not write that way, and one that breaks byte-identical round trips.
 */
final class PlainScalarLeaderTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function plainScalars(): array
    {
        return [
            'double dash flag'   => ["key: --cert-dir=/tmp\n"],
            'single dash'        => ["key: -single-dash\n"],
            'in a sequence'      => ["args:\n  - --kubelet-insecure-tls\n  - --metric-resolution=15s\n"],
            'question leader'    => ["key: ?query=1\n"],
            'colon leader'       => ["key: ::1\n"],
        ];
    }

    #[DataProvider('plainScalars')]
    public function testPlainLeadersRoundTripUnquoted(string $source): void
    {
        self::assertSame($source, (new Emitter())->emit((new YamlStringLoader())->load($source)));
    }

    /** @return array<string, array{string}> */
    public static function stillQuoted(): array
    {
        return [
            'bare dash'      => ["key: '-'\n"],
            'dash space'     => ["key: '- not a sequence'\n"],
            'negative number'=> ["key: '-5'\n"],
            'bare colon'     => ["key: ':'\n"],
            'bare question'  => ["key: '?'\n"],
        ];
    }

    /**
     * The relaxation must not go too far: a leader that really is an indicator,
     * or a value that would reparse as a non-string, still needs its quotes.
     */
    #[DataProvider('stillQuoted')]
    public function testGenuineIndicatorsKeepTheirQuotes(string $source): void
    {
        self::assertSame($source, (new Emitter())->emit((new YamlStringLoader())->load($source)));
    }
}
