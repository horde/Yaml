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
 * Stage 14 AI: `>` and `|` are block-scalar header indicators only
 * when followed by a valid header character (digit, `+`, `-`, space,
 * tab, newline, comment). `>=8.1`, `|=`, `>2x` etc. are plain
 * scalars starting with `>` or `|`.
 *
 * Regression: horde-installer-plugin.horde.yml ships
 * `php: >=8.1` and was over-folded into the next mapping line by
 * the multi-line plain scalar continuation logic introduced in
 * Stage 11 Q.
 */
#[CoversNothing]
final class BlockScalarVsPlainTest extends TestCase
{
    public function testGreaterEqualsIsPlain(): void
    {
        $r = (new YamlStringLoader())
            ->load("php: >=8.1\n")
            ->getDocument(0)
            ->root()
            ->resolved();
        $this->assertSame(['php' => '>=8.1'], $r);
    }

    public function testPipeFollowedByLetterIsPlain(): void
    {
        $r = (new YamlStringLoader())
            ->load("k: |alt\n")
            ->getDocument(0)
            ->root()
            ->resolved();
        $this->assertSame(['k' => '|alt'], $r);
    }

    public function testValidBlockScalarHeaderStillWorks(): void
    {
        $r = (new YamlStringLoader())
            ->load("k: |\n  hello\n")
            ->getDocument(0)
            ->root()
            ->resolved();
        $this->assertSame(['k' => "hello\n"], $r);
    }

    public function testFoldedBlockScalarStillWorks(): void
    {
        $r = (new YamlStringLoader())
            ->load("k: >\n  hello\n")
            ->getDocument(0)
            ->root()
            ->resolved();
        $this->assertSame(['k' => "hello\n"], $r);
    }

    public function testHordeInstallerPluginRegression(): void
    {
        // Real-world fixture from the .horde.yml corpus.
        $src = "dependencies:\n  required:\n    php: >=8.1\n    composer:\n      composer-plugin-api: ^2.2.0\n";
        $r = (new YamlStringLoader())
            ->load($src)
            ->getDocument(0)
            ->root()
            ->resolved();
        $this->assertSame(
            [
                'dependencies' => [
                    'required' => [
                        'php' => '>=8.1',
                        'composer' => [
                            'composer-plugin-api' => '^2.2.0',
                        ],
                    ],
                ],
            ],
            $r,
        );
    }
}
