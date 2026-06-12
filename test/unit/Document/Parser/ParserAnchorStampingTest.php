<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Verifies H.02 parser behavior: anchor stamping and AnchorIndex
 * registration.
 */
#[CoversNothing]
final class ParserAnchorStampingTest extends TestCase
{
    public function testAnchorStampedOnScalar(): void
    {
        $stream = (new YamlStringLoader())->load("foo: &my hello\n");
        $entry = $stream->getDocuments()[0]->root()->entry('foo');
        $value = $entry?->getValue();
        $this->assertInstanceOf(ScalarNode::class, $value);
        $this->assertSame('my', $value->getAnchor());
    }

    public function testAnchorRegisteredInDocumentIndex(): void
    {
        $stream = (new YamlStringLoader())->load("foo: &my hello\n");
        $doc = $stream->getDocuments()[0];
        $node = $doc->anchors()->lookup('my');
        $this->assertNotNull($node);
        $this->assertInstanceOf(ScalarNode::class, $node);
        $this->assertSame('hello', $node->getValue());
    }

    public function testAnchorOnNestedMap(): void
    {
        $source = "defaults: &defaults\n  timeout: 30\n  retries: 3\n";
        $stream = (new YamlStringLoader())->load($source);
        $doc = $stream->getDocuments()[0];
        $defaults = $doc->root()->entry('defaults')?->getValue();
        $this->assertInstanceOf(MapNode::class, $defaults);
        $this->assertSame('defaults', $defaults->getAnchor());
        $this->assertSame($defaults, $doc->anchors()->lookup('defaults'));
    }

    public function testTagStampedOnScalar(): void
    {
        $stream = (new YamlStringLoader())->load("foo: !mytag value\n");
        $value = $stream->getDocuments()[0]->root()->entry('foo')?->getValue();
        $this->assertInstanceOf(ScalarNode::class, $value);
        $this->assertSame('!mytag', $value->getTag());
    }

    public function testAnchorAndTagBoth(): void
    {
        $stream = (new YamlStringLoader())->load("foo: !!str &x 42\n");
        $value = $stream->getDocuments()[0]->root()->entry('foo')?->getValue();
        $this->assertInstanceOf(ScalarNode::class, $value);
        $this->assertSame('x', $value->getAnchor());
        $this->assertSame('!!str', $value->getTag());
    }

    public function testNoAnchorMeansNullField(): void
    {
        $stream = (new YamlStringLoader())->load("foo: hello\n");
        $value = $stream->getDocuments()[0]->root()->entry('foo')?->getValue();
        $this->assertNull($value->getAnchor());
        $this->assertNull($value->getTag());
    }

    public function testFreshDocumentHasEmptyAnchorIndex(): void
    {
        $stream = (new YamlStringLoader())->load("foo: hello\n");
        $doc = $stream->getDocuments()[0];
        $this->assertFalse($doc->anchors()->has('any'));
    }
}
