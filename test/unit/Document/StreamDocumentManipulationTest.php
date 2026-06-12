<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\YamlStream;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use OutOfRangeException;

/**
 * Verifies K.02 YamlStream document manipulation API.
 */
#[CoversClass(YamlStream::class)]
final class StreamDocumentManipulationTest extends TestCase
{
    public function testGetDocumentDefaultsToZero(): void
    {
        $stream = (new YamlStringLoader())->load("foo: 1\n");
        $this->assertSame($stream->getDocuments()[0], $stream->getDocument());
    }

    public function testGetDocumentByIndex(): void
    {
        $stream = (new YamlStringLoader())->load("---\na: 1\n---\nb: 2\n");
        $this->assertSame('a', $stream->getDocument(0)->root()->entries()[0]->getKeyString());
        $this->assertSame('b', $stream->getDocument(1)->root()->entries()[0]->getKeyString());
    }

    public function testGetDocumentOutOfRangeThrows(): void
    {
        $stream = (new YamlStringLoader())->load("foo: 1\n");
        $this->expectException(OutOfRangeException::class);
        $stream->getDocument(5);
    }

    public function testAppendDocumentDeepClones(): void
    {
        $source = (new YamlStringLoader())->load("foo: 1\n");
        $doc = $source->getDocument();
        $target = new YamlStream();
        $cloned = $target->appendDocument($doc);
        $this->assertSame(1, $target->documentCount());
        $this->assertNotSame($doc, $cloned);
        $this->assertSame(1, $cloned->root()->entry('foo')->getValue()->getValue());
    }

    public function testAppendDocumentSetsParentStream(): void
    {
        $source = (new YamlStringLoader())->load("foo: 1\n");
        $doc = $source->getDocument();
        $target = new YamlStream();
        $cloned = $target->appendDocument($doc);
        $this->assertSame($target, $cloned->parent());
    }

    public function testPrependDocument(): void
    {
        $stream = (new YamlStringLoader())->load("---\nfirst: 1\n");
        $other = (new YamlStringLoader())->load("zero: 0\n")->getDocument();
        $stream->prependDocument($other);
        $this->assertSame(2, $stream->documentCount());
        $this->assertSame('zero', $stream->getDocument(0)->root()->entries()[0]->getKeyString());
    }

    public function testInsertDocumentAt(): void
    {
        $stream = (new YamlStringLoader())->load("---\na: 1\n---\nc: 3\n");
        $other = (new YamlStringLoader())->load("b: 2\n")->getDocument();
        $stream->insertDocumentAt(1, $other);
        $this->assertSame(3, $stream->documentCount());
        $this->assertSame('b', $stream->getDocument(1)->root()->entries()[0]->getKeyString());
    }

    public function testInsertDocumentBefore(): void
    {
        $stream = (new YamlStringLoader())->load("---\na: 1\n---\nb: 2\n");
        $a = $stream->getDocument(0);
        $other = (new YamlStringLoader())->load("zero: 0\n")->getDocument();
        $stream->insertDocumentBefore($a, $other);
        $this->assertSame(3, $stream->documentCount());
        $this->assertSame('zero', $stream->getDocument(0)->root()->entries()[0]->getKeyString());
    }

    public function testInsertDocumentAfter(): void
    {
        $stream = (new YamlStringLoader())->load("---\na: 1\n---\nc: 3\n");
        $a = $stream->getDocument(0);
        $other = (new YamlStringLoader())->load("b: 2\n")->getDocument();
        $stream->insertDocumentAfter($a, $other);
        $this->assertSame(3, $stream->documentCount());
        $this->assertSame('b', $stream->getDocument(1)->root()->entries()[0]->getKeyString());
    }

    public function testRemoveDocument(): void
    {
        $stream = (new YamlStringLoader())->load("---\na: 1\n---\nb: 2\n");
        $stream->removeDocument(0);
        $this->assertSame(1, $stream->documentCount());
        $this->assertSame('b', $stream->getDocument()->root()->entries()[0]->getKeyString());
    }

    public function testCloneAcrossStreamsIndependent(): void
    {
        $source = (new YamlStringLoader())->load("foo: 1\n");
        $target = new YamlStream();
        $cloned = $target->appendDocument($source->getDocument());
        // Mutate the clone; source unchanged.
        $cloned->root()->entry('foo')->getValue()->setValue(999);
        $this->assertSame(1, $source->getDocument()->root()->entry('foo')->getValue()->getValue());
        $this->assertSame(999, $cloned->root()->entry('foo')->getValue()->getValue());
    }
}
