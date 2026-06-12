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
use Horde\Yaml\Document\TagHandlers\SetTagHandler;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Stage 12 Chapter X: explicit-key indicator (`?`) in block context
 * and the set tag.
 */
#[CoversNothing]
final class ExplicitKeyAndSetTest extends TestCase
{
    public function testQuestionLineStartsExplicitKeyEntry(): void
    {
        $stream = (new YamlStringLoader())->load("? admin\n? editor\n");
        $root = $stream->getDocument(0)->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $this->assertCount(2, $root->entries());
        $this->assertNotNull($root->entry('admin'));
        $this->assertNotNull($root->entry('editor'));
    }

    public function testExplicitKeyWithColonValue(): void
    {
        $src = "? key1\n: value1\n? key2\n: value2\n";
        $stream = (new YamlStringLoader())->load($src);
        $root = $stream->getDocument(0)->root();
        $resolved = $root->resolved();
        $this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $resolved);
    }

    public function testSetUnderRoleKey(): void
    {
        $src = "roles: !!set\n  ? admin\n  ? editor\n  ? viewer\n";
        $stream = (new YamlStringLoader())->load($src);
        $root = $stream->getDocument(0)->root();
        $roles = $root->entry('roles')->getValue();
        $this->assertInstanceOf(MapNode::class, $roles);
        $this->assertTrue(SetTagHandler::isSetShaped($roles));

        $resolved = $root->resolved();
        $this->assertSame(
            ['roles' => ['admin' => null, 'editor' => null, 'viewer' => null]],
            $resolved,
        );
    }

    public function testSetMixedWithRegularEntries(): void
    {
        $src = "config:\n  hosts: !!set\n    ? a\n    ? b\n  port: 80\n";
        $stream = (new YamlStringLoader())->load($src);
        $resolved = $stream->getDocument(0)->root()->resolved();
        $this->assertSame(
            [
                'config' => [
                    'hosts' => ['a' => null, 'b' => null],
                    'port' => 80,
                ],
            ],
            $resolved,
        );
    }

    public function testIsSetShapedRejectsNonNullValues(): void
    {
        $src = "x:\n  a: 1\n  b: null\n";
        $stream = (new YamlStringLoader())->load($src);
        $node = $stream->getDocument(0)->root()->entry('x')->getValue();
        $this->assertInstanceOf(MapNode::class, $node);
        $this->assertFalse(SetTagHandler::isSetShaped($node));
    }
}
