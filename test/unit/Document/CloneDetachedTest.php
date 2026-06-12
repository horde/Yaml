<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\YamlDocument;
use Horde\Yaml\Document\YamlStream;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies K.03 YamlDocument::cloneDetached().
 */
#[CoversClass(YamlDocument::class)]
final class CloneDetachedTest extends TestCase
{
    public function testCloneDetachedReturnsNewDocument(): void
    {
        $stream = (new YamlStringLoader())->load("foo: 1\n");
        $doc = $stream->getDocument();
        $clone = $doc->cloneDetached();
        $this->assertNotSame($doc, $clone);
    }

    public function testCloneIsInFreshStream(): void
    {
        $stream = (new YamlStringLoader())->load("foo: 1\n");
        $doc = $stream->getDocument();
        $clone = $doc->cloneDetached();
        $this->assertNotSame($stream, $clone->parent());
        $this->assertInstanceOf(YamlStream::class, $clone->parent());
        $this->assertSame(1, $clone->parent()->documentCount());
    }

    public function testCloneHasSameContent(): void
    {
        $stream = (new YamlStringLoader())->load("foo: 1\nbar: 2\n");
        $doc = $stream->getDocument();
        $clone = $doc->cloneDetached();
        $this->assertSame(1, $clone->root()->entry('foo')->getValue()->getValue());
        $this->assertSame(2, $clone->root()->entry('bar')->getValue()->getValue());
    }

    public function testMutationOnCloneDoesNotAffectOriginal(): void
    {
        $stream = (new YamlStringLoader())->load("foo: 1\n");
        $doc = $stream->getDocument();
        $clone = $doc->cloneDetached();
        $clone->root()->entry('foo')->getValue()->setValue(999);
        $this->assertSame(1, $doc->root()->entry('foo')->getValue()->getValue());
        $this->assertSame(999, $clone->root()->entry('foo')->getValue()->getValue());
    }

    public function testCloneOfDocumentWithAnchors(): void
    {
        $stream = (new YamlStringLoader())->load("a: &x hello\nb: *x\n");
        $doc = $stream->getDocument();
        $clone = $doc->cloneDetached();
        // Anchor reachable in clone too.
        $cloneB = $clone->root()->entry('b')->getValue();
        $this->assertSame('hello', $cloneB->target()->getValue());
    }

    public function testCloneAnchorRegistryIsIndependent(): void
    {
        $stream = (new YamlStringLoader())->load("a: &x hello\n");
        $doc = $stream->getDocument();
        $clone = $doc->cloneDetached();
        // Both have 'x' but they're different node instances.
        $original = $doc->anchors()->lookup('x');
        $copy = $clone->anchors()->lookup('x');
        $this->assertNotSame($original, $copy);
        $this->assertSame('hello', $copy?->getValue());
    }
}
