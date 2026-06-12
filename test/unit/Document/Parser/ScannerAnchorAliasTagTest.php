<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Parser\Scanner;
use Horde\Yaml\Document\ParseException;
use Horde\Yaml\Document\TokenType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies H.01 scanner behavior: Anchor, Alias, and Tag tokens.
 */
#[CoversClass(Scanner::class)]
final class ScannerAnchorAliasTagTest extends TestCase
{
    private function tokenAt(string $source, TokenType $type): \Horde\Yaml\Document\Token
    {
        $tokens = (new Scanner())->scan($source);
        foreach ($tokens as $t) {
            if ($t->type === $type) {
                return $t;
            }
        }
        throw new RuntimeException("No token of type $type->name found");
    }

    public function testAnchorOnPlainScalar(): void
    {
        $anchor = $this->tokenAt("foo: &my hello\n", TokenType::Anchor);
        $this->assertSame('my', $anchor->value);
    }

    public function testAliasReference(): void
    {
        $alias = $this->tokenAt("foo: *my\n", TokenType::Alias);
        $this->assertSame('my', $alias->value);
    }

    public function testTagPrimary(): void
    {
        $tag = $this->tokenAt("foo: !mytag value\n", TokenType::Tag);
        $this->assertSame('!mytag', $tag->value);
    }

    public function testTagSecondary(): void
    {
        $tag = $this->tokenAt('foo: !!str 42' . "\n", TokenType::Tag);
        $this->assertSame('!!str', $tag->value);
    }

    public function testTagWithHandle(): void
    {
        $tag = $this->tokenAt("foo: !my!Setting bar\n", TokenType::Tag);
        $this->assertSame('!my!Setting', $tag->value);
    }

    public function testAnchorBeforeTag(): void
    {
        $tokens = (new Scanner())->scan("foo: &x !!str hello\n");
        $types = array_values(array_filter(
            array_map(static fn($t): TokenType => $t->type, $tokens),
            static fn(TokenType $t) => $t === TokenType::Anchor || $t === TokenType::Tag,
        ));
        $this->assertSame([TokenType::Anchor, TokenType::Tag], $types);
    }

    public function testTagBeforeAnchor(): void
    {
        $tokens = (new Scanner())->scan("foo: !!str &x hello\n");
        $types = array_values(array_filter(
            array_map(static fn($t): TokenType => $t->type, $tokens),
            static fn(TokenType $t) => $t === TokenType::Anchor || $t === TokenType::Tag,
        ));
        $this->assertSame([TokenType::Tag, TokenType::Anchor], $types);
    }

    public function testAnchorOnSequenceItem(): void
    {
        $tokens = (new Scanner())->scan("- &x foo\n");
        $hasAnchor = false;
        foreach ($tokens as $t) {
            if ($t->type === TokenType::Anchor && $t->value === 'x') {
                $hasAnchor = true;
                break;
            }
        }
        $this->assertTrue($hasAnchor);
    }

    public function testAliasAsItemValue(): void
    {
        $tokens = (new Scanner())->scan("- *x\n");
        $hasAlias = false;
        foreach ($tokens as $t) {
            if ($t->type === TokenType::Alias && $t->value === 'x') {
                $hasAlias = true;
                break;
            }
        }
        $this->assertTrue($hasAlias);
    }

    public function testAnchorOnTopLevelScalar(): void
    {
        $anchor = $this->tokenAt("&top hello\n", TokenType::Anchor);
        $this->assertSame('top', $anchor->value);
    }

    public function testEmptyAnchorThrows(): void
    {
        $this->expectException(ParseException::class);
        (new Scanner())->scan("foo: & hello\n");
    }

    public function testBareBangIsNonSpecificTag(): void
    {
        // YAML 1.2 §6.9.1: `!` alone is the non-specific tag.
        // Stage 14 AO accepts it where Stage 7 had thrown.
        $tokens = (new Scanner())->scan("foo: ! hello\n");
        $tagToken = null;
        foreach ($tokens as $t) {
            if ($t->type->name === 'Tag') {
                $tagToken = $t;
                break;
            }
        }
        $this->assertNotNull($tagToken);
        $this->assertSame('!', $tagToken->value);
    }

    public function testDoubleBangAloneStillRejected(): void
    {
        // `!!` (handle without suffix) is malformed.
        $this->expectException(ParseException::class);
        (new Scanner())->scan("foo: !! hello\n");
    }
}
