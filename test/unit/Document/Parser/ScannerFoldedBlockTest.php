<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Node\ChompMode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\Parser\Scanner;
use Horde\Yaml\Document\Token;
use Horde\Yaml\Document\TokenType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies F.02 scanner behavior: folded block scalar (`>`)
 * recognition with line folding.
 */
#[CoversClass(Scanner::class)]
final class ScannerFoldedBlockTest extends TestCase
{
    private function scalarTokenAfterValue(string $source): Token
    {
        $tokens = (new Scanner())->scan($source);
        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i]->type === TokenType::Value
                && isset($tokens[$i + 1])
                && $tokens[$i + 1]->type === TokenType::Scalar
            ) {
                return $tokens[$i + 1];
            }
        }
        throw new RuntimeException('No scalar token after Value');
    }

    public function testSimpleFoldedBlock(): void
    {
        // Folded: consecutive content lines join with a space.
        $source = "key: >\n  line one\n  line two\n";
        $token = $this->scalarTokenAfterValue($source);
        $this->assertSame(ScalarStyle::FoldedBlock, $token->style);
        $this->assertSame("line one line two\n", $token->value);
    }

    public function testFoldedBlockEmptyLineBecomesNewline(): void
    {
        // An empty line between content folds to a newline (not space).
        $source = "key: >\n  paragraph one\n\n  paragraph two\n";
        $token = $this->scalarTokenAfterValue($source);
        $this->assertSame("paragraph one\nparagraph two\n", $token->value);
    }

    public function testFoldedBlockChompModes(): void
    {
        $source = "key: >-\n  line one\n  line two\n";
        $token = $this->scalarTokenAfterValue($source);
        $this->assertSame(ChompMode::Strip, $token->chomp);
        $this->assertSame('line one line two', $token->value);
    }

    public function testFoldedBlockKeepChomp(): void
    {
        $source = "key: >+\n  line\n\n\n";
        $token = $this->scalarTokenAfterValue($source);
        $this->assertSame(ChompMode::Keep, $token->chomp);
        $this->assertStringEndsWith("\n\n\n", $token->value);
    }

    public function testFoldedBlockExplicitIndent(): void
    {
        $source = "key: >2\n  line one\n  line two\n";
        $token = $this->scalarTokenAfterValue($source);
        $this->assertSame(2, $token->indentIndicator);
        $this->assertSame("line one line two\n", $token->value);
    }
}
