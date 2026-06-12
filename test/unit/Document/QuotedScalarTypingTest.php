<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies E.03 resolver behavior end-to-end: quoted scalars (single
 * and double) stay strings regardless of content; plain scalars
 * receive YAML 1.2 core schema typing.
 */
#[CoversNothing]
final class QuotedScalarTypingTest extends TestCase
{
    private function rootScalar(string $yaml): ScalarNode
    {
        $stream = (new YamlStringLoader())->load($yaml);
        $root = $stream->getDocuments()[0]->root();
        if (!$root instanceof ScalarNode) {
            throw new RuntimeException('Expected ScalarNode root');
        }
        return $root;
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function quotedSamples(): iterable
    {
        yield 'single-quoted 42' => ["'42'", '42'];
        yield 'double-quoted 42' => ['"42"', '42'];
        yield 'single-quoted true' => ["'true'", 'true'];
        yield 'double-quoted true' => ['"true"', 'true'];
        yield 'single-quoted null' => ["'null'", 'null'];
        yield 'double-quoted null' => ['"null"', 'null'];
        yield 'single-quoted hex' => ["'0xFF'", '0xFF'];
        yield 'double-quoted float' => ['"3.14"', '3.14'];
    }

    #[DataProvider('quotedSamples')]
    public function testQuotedScalarStaysString(string $source, string $expected): void
    {
        $node = $this->rootScalar($source);
        $this->assertSame($expected, $node->getValue());
        $this->assertContains(
            $node->getStyle(),
            [ScalarStyle::SingleQuoted, ScalarStyle::DoubleQuoted],
        );
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function plainSamples(): iterable
    {
        yield 'plain 42' => ['42', 42];
        yield 'plain true' => ['true', true];
        yield 'plain null' => ['null', null];
        yield 'plain 0xFF' => ['0xFF', 255];
        yield 'plain 3.14' => ['3.14', 3.14];
    }

    #[DataProvider('plainSamples')]
    public function testPlainScalarReceivesTyping(string $source, mixed $expected): void
    {
        $node = $this->rootScalar($source);
        $this->assertSame($expected, $node->getValue());
        $this->assertSame(ScalarStyle::Plain, $node->getStyle());
    }
}
