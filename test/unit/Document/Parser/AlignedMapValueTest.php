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
 * Stage 14 AJ: multiple spaces / tabs between `:` and the value
 * (column-aligned mappings).
 */
#[CoversNothing]
final class AlignedMapValueTest extends TestCase
{
    public function testMultipleSpacesAfterColon(): void
    {
        $r = (new YamlStringLoader())
            ->load("k:   v\n")
            ->getDocument(0)
            ->root()
            ->resolved();
        $this->assertSame(['k' => 'v'], $r);
    }

    public function testColumnAlignedMapping(): void
    {
        $src = "name: Mark\nhr:   65\navg:  0.278\n";
        $r = (new YamlStringLoader())
            ->load($src)
            ->getDocument(0)
            ->root()
            ->resolved();
        $this->assertSame(
            ['name' => 'Mark', 'hr' => 65, 'avg' => 0.278],
            $r,
        );
    }

    public function testTabAfterColon(): void
    {
        $r = (new YamlStringLoader())
            ->load("k:\tv\n")
            ->getDocument(0)
            ->root()
            ->resolved();
        $this->assertSame(['k' => 'v'], $r);
    }

    public function testEmptyDashWithNestedMap(): void
    {
        $src = "-\n  name: Mark\n  hr:   65\n  avg:  0.278\n-\n  name: Sammy\n";
        $r = (new YamlStringLoader())->load($src)->getDocument(0)->root();
        $this->assertCount(2, $r->items());
    }
}
