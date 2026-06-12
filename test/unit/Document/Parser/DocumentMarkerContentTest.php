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
use Horde\Yaml\Document\ParseException;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Stage 13 Chapter AA: content on the document-start (`---`) line.
 *
 * The marker line may carry a plain or quoted scalar, a tag/anchor
 * (whose value lives on the next indented line), or a block-scalar
 * header. It may NOT carry a block mapping or block sequence.
 * Those need a fresh line per YAML 1.2 §9.1.2.
 */
#[CoversNothing]
final class DocumentMarkerContentTest extends TestCase
{
    public function testPlainScalarOnMarkerLine(): void
    {
        $doc = (new YamlStringLoader())->load("--- text\n")->getDocument(0);
        $root = $doc->root();
        $this->assertInstanceOf(ScalarNode::class, $root);
        $this->assertSame('text', $root->getValue());
    }

    public function testTaggedScalarOnMarkerLine(): void
    {
        $doc = (new YamlStringLoader())->load("--- !!str foo\n")->getDocument(0);
        $root = $doc->root();
        $this->assertInstanceOf(ScalarNode::class, $root);
        $this->assertSame('!!str', $root->getTag());
        $this->assertSame('foo', $root->getValue());
    }

    public function testTagOnMarkerLineWithBlockMapBelow(): void
    {
        $src = "--- !MyTag\n  a: 1\n  b: 2\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument(0);
        $root = $doc->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $this->assertSame('!MyTag', $root->getTag());
        $this->assertSame(['a' => 1, 'b' => 2], $root->resolved());
    }

    public function testBlockScalarHeaderOnMarkerLine(): void
    {
        $src = "--- |\n  hello\n  world\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument(0);
        $root = $doc->root();
        $this->assertInstanceOf(ScalarNode::class, $root);
        $this->assertSame("hello\nworld\n", $root->getValue());
    }

    public function testCommentAfterMarker(): void
    {
        $src = "--- # heading\nkey: 1\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument(0);
        $this->assertSame(['key' => 1], $doc->root()->resolved());
    }

    public function testBlockMappingOnMarkerLineRejected(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/block mapping cannot start on the document marker/');
        (new YamlStringLoader())->load("--- key: value\n");
    }

    public function testBlockSequenceOnMarkerLineRejected(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/block sequence cannot start on the document marker/');
        (new YamlStringLoader())->load("--- - item\n");
    }
}
