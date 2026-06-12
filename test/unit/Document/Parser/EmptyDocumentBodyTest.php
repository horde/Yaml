<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Stage 13 Chapter AB: empty document body is legal. The root
 * stays null when no node appears between markers.
 */
#[CoversNothing]
final class EmptyDocumentBodyTest extends TestCase
{
    public function testEmptyBetweenStartMarkers(): void
    {
        $stream = (new YamlStringLoader())->load("---\n---\n");
        $this->assertCount(2, $stream->getDocuments());
        $this->assertNull($stream->getDocument(0)->root());
        $this->assertNull($stream->getDocument(1)->root());
    }

    public function testStartMarkerOnlyDocument(): void
    {
        $stream = (new YamlStringLoader())->load("---\n");
        $this->assertCount(1, $stream->getDocuments());
        $this->assertNull($stream->getDocument(0)->root());
    }

    public function testEmptyDocumentFollowedByContent(): void
    {
        $stream = (new YamlStringLoader())->load("---\n...\n---\nfoo\n");
        $this->assertCount(2, $stream->getDocuments());
        $this->assertNull($stream->getDocument(0)->root());
        $this->assertSame('foo', (string) $stream->getDocument(1)->root());
    }
}
