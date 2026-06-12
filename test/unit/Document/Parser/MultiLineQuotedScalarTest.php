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
 * Stage 13 Chapter AD: multi-line quoted scalars per YAML 1.2
 * §7.4.1 (single-quoted) and §7.5.2 (double-quoted).
 */
#[CoversNothing]
final class MultiLineQuotedScalarTest extends TestCase
{
    private function valueOf(string $src): string
    {
        return (string) (new YamlStringLoader())
            ->load($src)
            ->getDocument(0)
            ->root()
            ->entry('k')
            ->getValue()
            ->getValue();
    }

    public function testDoubleQuotedSingleLineBreakFoldsToSpace(): void
    {
        $this->assertSame(
            'line one line two',
            $this->valueOf("k: \"line one\n  line two\"\n"),
        );
    }

    public function testDoubleQuotedBlankLineFoldsToNewline(): void
    {
        $this->assertSame(
            "line one\nline two",
            $this->valueOf("k: \"line one\n\n  line two\"\n"),
        );
    }

    public function testDoubleQuotedBackslashNewlineSuppressesLineBreak(): void
    {
        // `\<newline>` removes the newline and the next line's leading
        // whitespace entirely.
        $this->assertSame(
            'line oneline two',
            $this->valueOf("k: \"line one\\\n  line two\"\n"),
        );
    }

    public function testSingleQuotedSingleLineBreakFoldsToSpace(): void
    {
        $this->assertSame(
            'line one line two',
            $this->valueOf("k: 'line one\n  line two'\n"),
        );
    }

    public function testSingleQuotedBlankLineFoldsToNewline(): void
    {
        $this->assertSame(
            "line one\nline two",
            $this->valueOf("k: 'line one\n\n  line two'\n"),
        );
    }

    public function testTrailingWhitespaceStrippedBeforeFold(): void
    {
        $this->assertSame(
            'a b',
            $this->valueOf("k: \"a   \n  b\"\n"),
        );
    }
}
