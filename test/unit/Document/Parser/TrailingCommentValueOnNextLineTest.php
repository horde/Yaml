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
 * Stage 14 AK: trailing comment after `:` with the value on the
 * next indented line.
 */
#[CoversNothing]
final class TrailingCommentValueOnNextLineTest extends TestCase
{
    public function testCommentAfterColonValueOnNextLine(): void
    {
        $r = (new YamlStringLoader())
            ->load("key:    # Comment\n  value\n")
            ->getDocument(0)
            ->root()
            ->resolved();
        $this->assertSame(['key' => 'value'], $r);
    }

    public function testCommentAfterColonNestedMapBelow(): void
    {
        $src = "section: # heading\n  a: 1\n  b: 2\n";
        $r = (new YamlStringLoader())
            ->load($src)
            ->getDocument(0)
            ->root()
            ->resolved();
        $this->assertSame(['section' => ['a' => 1, 'b' => 2]], $r);
    }

    public function testCommentAfterColonSequenceBelow(): void
    {
        $src = "items: # ranking\n  - one\n  - two\n";
        $r = (new YamlStringLoader())
            ->load($src)
            ->getDocument(0)
            ->root()
            ->resolved();
        $this->assertSame(['items' => ['one', 'two']], $r);
    }
}
