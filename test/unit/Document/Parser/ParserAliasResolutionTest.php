<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Node\AliasNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\UnresolvedAliasException;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Verifies H.03 parser behavior: AliasNode construction and target
 * resolution via the document's anchor index.
 */
#[CoversNothing]
final class ParserAliasResolutionTest extends TestCase
{
    public function testAliasNodeProducedAtUseSite(): void
    {
        $stream = (new YamlStringLoader())->load("a: &x hello\nb: *x\n");
        $b = $stream->getDocuments()[0]->root()->entry('b')?->getValue();
        $this->assertInstanceOf(AliasNode::class, $b);
        $this->assertSame('x', $b->getTargetName());
    }

    public function testAliasResolvesToAnchoredNode(): void
    {
        $stream = (new YamlStringLoader())->load("a: &x hello\nb: *x\n");
        $b = $stream->getDocuments()[0]->root()->entry('b')?->getValue();
        $this->assertInstanceOf(AliasNode::class, $b);
        $target = $b->target();
        $this->assertInstanceOf(ScalarNode::class, $target);
        $this->assertSame('hello', $target->getValue());
    }

    public function testUnresolvedAliasThrows(): void
    {
        // Anchor never defined. Alias resolution at call time fails.
        // Note: scanner/parser don't validate at parse time per Q10.
        $stream = (new YamlStringLoader())->load("b: *missing\n");
        $alias = $stream->getDocuments()[0]->root()->entry('b')?->getValue();
        $this->assertInstanceOf(AliasNode::class, $alias);
        $this->expectException(UnresolvedAliasException::class);
        $alias->target();
    }

    public function testTwoAliasesShareTarget(): void
    {
        $source = "a: &x hello\nb: *x\nc: *x\n";
        $stream = (new YamlStringLoader())->load($source);
        $root = $stream->getDocuments()[0]->root();
        $b = $root->entry('b')?->getValue();
        $c = $root->entry('c')?->getValue();
        $this->assertInstanceOf(AliasNode::class, $b);
        $this->assertInstanceOf(AliasNode::class, $c);
        $this->assertSame($b->target(), $c->target());
    }

    public function testAnchoredMapResolvedThroughAlias(): void
    {
        $source = "defaults: &d\n  timeout: 30\nprod: *d\n";
        $stream = (new YamlStringLoader())->load($source);
        $root = $stream->getDocuments()[0]->root();
        $alias = $root->entry('prod')?->getValue();
        $this->assertInstanceOf(AliasNode::class, $alias);
        $target = $alias->target();
        $this->assertSame($root->entry('defaults')->getValue(), $target);
    }
}
