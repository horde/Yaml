<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Node;

use Horde\Yaml\Document\Node\ChompMode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScalarNode::class)]
final class ScalarNodeApiTest extends TestCase
{
    public function testGetSetValue(): void
    {
        $node = new ScalarNode(42);
        $this->assertSame(42, $node->getValue());
        $node->setValue('text');
        $this->assertSame('text', $node->getValue());
    }

    public function testSetValueClearsRawSource(): void
    {
        $node = new ScalarNode(255, ScalarStyle::Plain, '0xFF');
        $this->assertSame('0xFF', $node->getRawSource());
        $node->setValue(42);
        $this->assertNull($node->getRawSource());
    }

    public function testGetSetStyle(): void
    {
        $node = new ScalarNode('x');
        $this->assertSame(ScalarStyle::Plain, $node->getStyle());
        $node->setStyle(ScalarStyle::DoubleQuoted);
        $this->assertSame(ScalarStyle::DoubleQuoted, $node->getStyle());
    }

    public function testGetSetChomp(): void
    {
        $node = new ScalarNode('content', ScalarStyle::LiteralBlock);
        $node->setChomp(ChompMode::Strip);
        $this->assertSame(ChompMode::Strip, $node->getChomp());
        $node->setChomp(null);
        $this->assertNull($node->getChomp());
    }

    public function testGetSetIndentIndicator(): void
    {
        $node = new ScalarNode('x', ScalarStyle::LiteralBlock);
        $node->setIndentIndicator(2);
        $this->assertSame(2, $node->getIndentIndicator());
        $node->setIndentIndicator(null);
        $this->assertNull($node->getIndentIndicator());
    }

    public function testGetSetAnchor(): void
    {
        $node = new ScalarNode('x');
        $node->setAnchor('myAnchor');
        $this->assertSame('myAnchor', $node->getAnchor());
        $node->setAnchor(null);
        $this->assertNull($node->getAnchor());
    }

    public function testGetSetTag(): void
    {
        $node = new ScalarNode('x');
        $node->setTag('!!str');
        $this->assertSame('!!str', $node->getTag());
        $node->setTag(null);
        $this->assertNull($node->getTag());
    }

    public function testStringableForVariousValues(): void
    {
        $this->assertSame('42', (string) new ScalarNode(42));
        $this->assertSame('hello', (string) new ScalarNode('hello'));
        $this->assertSame('1', (string) new ScalarNode(true));
        $this->assertSame('', (string) new ScalarNode(false));
        $this->assertSame('', (string) new ScalarNode(null));
    }
}
