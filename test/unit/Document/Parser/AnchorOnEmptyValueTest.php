<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Stage 14 AL: anchor or tag with no following content materialises
 * an empty scalar node, so the anchor / tag is preserved and aliases
 * resolve.
 */
#[CoversNothing]
final class AnchorOnEmptyValueTest extends TestCase
{
    public function testAnchorAloneAttachesToEmptyScalar(): void
    {
        $stream = (new YamlStringLoader())->load("a: &anchor\nb: *anchor\n");
        $r = $stream->getDocument(0)->root();
        $value = $r->entry('a')->getValue();
        $this->assertInstanceOf(ScalarNode::class, $value);
        $this->assertSame('anchor', $value->getAnchor());
        $this->assertNull($value->getValue());
    }

    public function testAliasResolvesToEmptyScalar(): void
    {
        $stream = (new YamlStringLoader())->load("a: &anchor\nb: *anchor\n");
        $r = $stream->getDocument(0)->root()->resolved();
        $this->assertSame(['a' => null, 'b' => null], $r);
    }

    public function testTagAloneAttachesToEmptyScalar(): void
    {
        $stream = (new YamlStringLoader())->load("a: !!null\nb: 1\n");
        $value = $stream->getDocument(0)->root()->entry('a')->getValue();
        $this->assertInstanceOf(ScalarNode::class, $value);
        $this->assertNull($value->getValue());
    }
}
