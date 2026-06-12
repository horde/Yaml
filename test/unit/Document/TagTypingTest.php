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
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies H.05 end-to-end: explicit tags override style and regex
 * resolution. Plain scalars get core schema; quoted/block stay
 * strings unless tagged.
 */
#[CoversNothing]
final class TagTypingTest extends TestCase
{
    private function rootEntryValue(string $yaml, string $key): mixed
    {
        $stream = (new YamlStringLoader())->load($yaml);
        $value = $stream->getDocuments()[0]->root()->entry($key)?->getValue();
        $this->assertInstanceOf(ScalarNode::class, $value);
        return $value->getValue();
    }

    public function testStrTagForcesString(): void
    {
        $value = $this->rootEntryValue("a: !!str 42\n", 'a');
        $this->assertSame('42', $value);
    }

    public function testIntTagOnQuotedString(): void
    {
        $value = $this->rootEntryValue('a: !!int "42"' . "\n", 'a');
        $this->assertSame(42, $value);
    }

    public function testBoolTagOnTrue(): void
    {
        $value = $this->rootEntryValue("a: !!bool true\n", 'a');
        $this->assertTrue($value);
    }

    public function testBoolTagOnFalse(): void
    {
        $value = $this->rootEntryValue("a: !!bool false\n", 'a');
        $this->assertFalse($value);
    }

    public function testNullTagOnNullSpelling(): void
    {
        $value = $this->rootEntryValue("a: !!null null\n", 'a');
        $this->assertNull($value);
    }

    public function testNullTagOnNonEmptyThrows(): void
    {
        $this->expectException(\Horde\Yaml\Document\ParseException::class);
        $this->rootEntryValue("a: !!null whatever\n", 'a');
    }

    public function testFloatTag(): void
    {
        $value = $this->rootEntryValue("a: !!float 3.14\n", 'a');
        $this->assertSame(3.14, $value);
    }

    public function testCustomTagLeavesStringContent(): void
    {
        $value = $this->rootEntryValue("a: !mytag value\n", 'a');
        $this->assertSame('value', $value);
    }

    public function testTagPreservedOnNode(): void
    {
        $stream = (new YamlStringLoader())->load("a: !!str 42\n");
        $node = $stream->getDocuments()[0]->root()->entry('a')->getValue();
        $this->assertSame('!!str', $node->getTag());
    }
}
