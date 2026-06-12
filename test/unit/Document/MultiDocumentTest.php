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
use PHPUnit\Framework\TestCase;

/**
 * Verifies K.01 multi-document streams: parser handles ---separated
 * documents and each is round-trippable.
 */
#[CoversNothing]
final class MultiDocumentTest extends TestCase
{
    public function testTwoDocumentsParseAndCount(): void
    {
        $stream = (new YamlStringLoader())->load("---\nfoo: 1\n---\nbar: 2\n");
        $this->assertSame(2, $stream->documentCount());
    }

    public function testDocumentsHaveCorrectContent(): void
    {
        $stream = (new YamlStringLoader())->load("---\nfoo: 1\n---\nbar: 2\n");
        $docs = $stream->getDocuments();
        $this->assertSame(1, $docs[0]->root()->entry('foo')->getValue()->getValue());
        $this->assertSame(2, $docs[1]->root()->entry('bar')->getValue()->getValue());
    }

    public function testDocumentMarkersPreserved(): void
    {
        $stream = (new YamlStringLoader())->load("---\nfoo: 1\n---\nbar: 2\n");
        $docs = $stream->getDocuments();
        $this->assertTrue($docs[0]->getStartMarker());
        $this->assertTrue($docs[1]->getStartMarker());
    }

    public function testTwoDocsRoundTrip(): void
    {
        $src = "---\nfoo: 1\n---\nbar: 2\n";
        $stream = (new YamlStringLoader())->load($src);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($src, $out);
    }

    public function testThreeDocs(): void
    {
        $src = "---\na: 1\n---\nb: 2\n---\nc: 3\n";
        $stream = (new YamlStringLoader())->load($src);
        $this->assertSame(3, $stream->documentCount());
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($src, $out);
    }

    public function testEndMarker(): void
    {
        $src = "---\nfoo: 1\n...\n---\nbar: 2\n";
        $stream = (new YamlStringLoader())->load($src);
        $this->assertSame(2, $stream->documentCount());
        $this->assertTrue($stream->getDocuments()[0]->getEndMarker());
    }
}
