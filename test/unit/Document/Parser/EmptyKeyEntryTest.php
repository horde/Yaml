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
 * Stage 13 Chapter AE: empty key in block mapping (`: value`).
 */
#[CoversNothing]
final class EmptyKeyEntryTest extends TestCase
{
    public function testEmptyKeyEntryAtTopLevel(): void
    {
        $stream = (new YamlStringLoader())->load(": value\n");
        $resolved = $stream->getDocument(0)->root()->resolved();
        $this->assertSame(['' => 'value'], $resolved);
    }

    public function testEmptyKeyEntryWithNullValue(): void
    {
        $stream = (new YamlStringLoader())->load(":\n");
        $root = $stream->getDocument(0)->root();
        $this->assertNotNull($root);
    }

    public function testEmptyAndNamedKeysMixed(): void
    {
        $src = ": empty-key-value\nname: alice\n";
        $stream = (new YamlStringLoader())->load($src);
        $resolved = $stream->getDocument(0)->root()->resolved();
        $this->assertSame(
            ['' => 'empty-key-value', 'name' => 'alice'],
            $resolved,
        );
    }
}
