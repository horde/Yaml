<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Node;

use Horde\Yaml\Document\Node\AliasNode;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Verifies J.03 AliasNode::detach(): replaces the alias with a deep
 * clone of the target; the original target is unchanged.
 */
#[CoversNothing]
final class AliasDetachTest extends TestCase
{
    public function testDetachReplacesAliasWithClone(): void
    {
        $source = "defaults: &d\n  timeout: 30\nprod:\n  <<: *d\n  host: prod\n";
        $stream = (new YamlStringLoader())->load($source);
        $prod = $stream->getDocuments()[0]->root()->entry('prod')->getValue();
        $alias = $prod->mergeEntry()->getValue();
        $this->assertInstanceOf(AliasNode::class, $alias);

        $cloned = $alias->detach();
        $this->assertInstanceOf(MapNode::class, $cloned);
        $this->assertSame($cloned, $prod->mergeEntry()->getValue());
    }

    public function testDetachedClonesHasNoAnchor(): void
    {
        $source = "defaults: &d\n  timeout: 30\nprod:\n  <<: *d\n";
        $stream = (new YamlStringLoader())->load($source);
        $prod = $stream->getDocuments()[0]->root()->entry('prod')->getValue();
        $alias = $prod->mergeEntry()->getValue();
        $cloned = $alias->detach();
        $this->assertNull($cloned->getAnchor());
    }

    public function testOriginalAnchoredNodeUnchanged(): void
    {
        $source = "defaults: &d\n  timeout: 30\nprod:\n  <<: *d\n";
        $stream = (new YamlStringLoader())->load($source);
        $defaults = $stream->getDocuments()[0]->root()->entry('defaults')->getValue();
        $prod = $stream->getDocuments()[0]->root()->entry('prod')->getValue();
        $alias = $prod->mergeEntry()->getValue();
        $alias->detach();
        $this->assertSame('d', $defaults->getAnchor());
        $this->assertSame(30, $defaults->resolved()['timeout']);
    }

    public function testCloneHasNewIdentity(): void
    {
        $source = "defaults: &d\n  timeout: 30\nprod:\n  <<: *d\n";
        $stream = (new YamlStringLoader())->load($source);
        $defaults = $stream->getDocuments()[0]->root()->entry('defaults')->getValue();
        $prod = $stream->getDocuments()[0]->root()->entry('prod')->getValue();
        $alias = $prod->mergeEntry()->getValue();
        $cloned = $alias->detach();
        $this->assertNotSame($defaults, $cloned);
        // Verify entries are also distinct.
        $this->assertNotSame(
            $defaults->entry('timeout'),
            $cloned->entry('timeout'),
        );
    }

    public function testDetachOnSequenceItemAlias(): void
    {
        $source = "shared: &s hello\nlist:\n  - *s\n";
        $stream = (new YamlStringLoader())->load($source);
        $list = $stream->getDocuments()[0]->root()->entry('list')->getValue();
        $alias = $list->item(0)->getValue();
        $this->assertInstanceOf(AliasNode::class, $alias);
        $cloned = $alias->detach();
        $this->assertInstanceOf(ScalarNode::class, $cloned);
        $this->assertSame('hello', $cloned->getValue());
        $this->assertSame($cloned, $list->item(0)->getValue());
    }

    public function testDetachedAliasHasNoParent(): void
    {
        $source = "shared: &s hello\nref: *s\n";
        $stream = (new YamlStringLoader())->load($source);
        $entry = $stream->getDocuments()[0]->root()->entry('ref');
        $alias = $entry->getValue();
        $this->assertInstanceOf(AliasNode::class, $alias);
        $alias->detach();
        $this->assertNull($alias->parent());
    }
}
