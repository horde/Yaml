<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\ParseException;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Stage 11 Chapter R: YAML 1.2 core schema tag coercion errors.
 *
 * Existing happy-path coverage lives in TagTypingTest. This file
 * verifies that invalid input under each core tag throws rather
 * than silently coercing.
 */
#[CoversNothing]
final class CoreSchemaTagErrorTest extends TestCase
{
    public function testIntTagRejectsNonInteger(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/!!int/');
        (new YamlStringLoader())->load("x: !!int abc\n");
    }

    public function testIntTagRejectsFloat(): void
    {
        $this->expectException(ParseException::class);
        (new YamlStringLoader())->load("x: !!int 3.14\n");
    }

    public function testBoolTagRejectsNonBoolean(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/!!bool/');
        (new YamlStringLoader())->load("x: !!bool xyz\n");
    }

    public function testNullTagRejectsNonNullSource(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/!!null/');
        (new YamlStringLoader())->load("x: !!null whatever\n");
    }

    public function testFloatTagRejectsNonNumeric(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/!!float/');
        (new YamlStringLoader())->load("x: !!float xyz\n");
    }

    public function testFloatTagAcceptsInteger(): void
    {
        // 5 is a valid float (5.0).
        $doc = (new YamlStringLoader())->load("x: !!float 5\n")->getDocument(0);
        $value = $doc->root()->entry('x')->getValue()->getValue();
        $this->assertSame(5.0, $value);
    }

    public function testStrTagPreservesNumericLooking(): void
    {
        $doc = (new YamlStringLoader())->load("x: !!str 42\n")->getDocument(0);
        $value = $doc->root()->entry('x')->getValue()->getValue();
        $this->assertSame('42', $value);
    }

    public function testCustomTagPassesThrough(): void
    {
        $doc = (new YamlStringLoader())->load("x: !MyType hello\n")->getDocument(0);
        $value = $doc->root()->entry('x')->getValue();
        $this->assertSame('hello', $value->getValue());
        $this->assertSame('!MyType', $value->getTag());
    }
}
