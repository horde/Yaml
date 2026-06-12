<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stage 11 Chapter S: opt-in YAML 1.1 boolean compatibility flag.
 */
#[CoversNothing]
final class LegacyBooleansFlagTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function legacySpellings(): iterable
    {
        foreach (['yes', 'Yes', 'YES', 'y', 'Y', 'on', 'On', 'ON'] as $s) {
            yield $s => [$s, true];
        }
        foreach (['no', 'No', 'NO', 'n', 'N', 'off', 'Off', 'OFF'] as $s) {
            yield $s => [$s, false];
        }
    }

    #[DataProvider('legacySpellings')]
    public function testFlagOnCoercesAllSpellings(string $token, bool $expected): void
    {
        $stream = (new YamlStringLoader(legacyBooleans: true))->load("x: $token\n");
        $value = $stream->getDocument(0)->root()->entry('x')->getValue()->getValue();
        $this->assertSame($expected, $value);
    }

    #[DataProvider('legacySpellings')]
    public function testFlagOffKeepsAllSpellingsAsStrings(string $token): void
    {
        $stream = (new YamlStringLoader())->load("x: $token\n");
        $value = $stream->getDocument(0)->root()->entry('x')->getValue()->getValue();
        $this->assertSame($token, $value);
    }

    public function testFlagDoesNotAffectQuoted(): void
    {
        $src = "a: \"yes\"\nb: 'no'\n";
        $stream = (new YamlStringLoader(legacyBooleans: true))->load($src);
        $resolved = $stream->getDocument(0)->root()->resolved();
        $this->assertSame(['a' => 'yes', 'b' => 'no'], $resolved);
    }

    public function testFlagPreservesSourceBytesOnRoundTrip(): void
    {
        $src = "state: yes\nsecure: no\n";
        $stream = (new YamlStringLoader(legacyBooleans: true))->load($src);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($src, $out);
    }

    public function testFlagDoesNotAffectStrictBooleans(): void
    {
        $stream = (new YamlStringLoader())->load("a: true\nb: false\n");
        $r = $stream->getDocument(0)->root()->resolved();
        $this->assertSame(['a' => true, 'b' => false], $r);
    }
}
