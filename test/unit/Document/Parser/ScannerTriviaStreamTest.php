<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Parser\Scanner;
use Horde\Yaml\Document\Token;
use Horde\Yaml\Document\TokenType;
use Horde\Yaml\Document\TriviaToken;
use Horde\Yaml\Document\TriviaType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies G.01 scanner behavior: standalone comments and blank-line
 * groups appear in the token stream as TriviaToken instances on the
 * leadingTrivia / trailingTrivia of structural tokens, in source
 * order. The scanner does not assign ownership; parser-level
 * migration into AST CommentNode/BlankLineNode siblings happens in
 * G.02.
 *
 * The contract verified here:
 * - Every standalone comment appears exactly once in some token's
 *   leadingTrivia (or in StreamEnd's leadingTrivia for
 *   end-of-document comments).
 * - Blank-line groups appear with their count.
 * - Source order is preserved across the trivia attached to
 *   adjacent tokens.
 */
#[CoversClass(Scanner::class)]
final class ScannerTriviaStreamTest extends TestCase
{
    /**
     * Walk all tokens and collect trivia in source order.
     *
     * @return list<TriviaToken>
     */
    private function allTrivia(string $source): array
    {
        $tokens = (new Scanner())->scan($source);
        $trivia = [];
        foreach ($tokens as $token) {
            foreach ($token->leadingTrivia as $tr) {
                $trivia[] = $tr;
            }
            foreach ($token->trailingTrivia as $tr) {
                $trivia[] = $tr;
            }
        }
        return $trivia;
    }

    public function testLeadingFileCommentPreserved(): void
    {
        $trivia = $this->allTrivia("# leading\nfoo: 1\n");
        $comments = array_values(array_filter(
            $trivia,
            static fn($t) => $t->type === TriviaType::Comment,
        ));
        $this->assertCount(1, $comments);
        $this->assertSame('# leading', $comments[0]->text);
    }

    public function testCommentBetweenEntriesPreserved(): void
    {
        $trivia = $this->allTrivia("foo: 1\n# between\nbar: 2\n");
        $comments = array_values(array_filter(
            $trivia,
            static fn($t) => $t->type === TriviaType::Comment,
        ));
        $this->assertCount(1, $comments);
        $this->assertSame('# between', $comments[0]->text);
    }

    public function testTrailingFileCommentPreserved(): void
    {
        $trivia = $this->allTrivia("foo: 1\n# trailing\n");
        $comments = array_values(array_filter(
            $trivia,
            static fn($t) => $t->type === TriviaType::Comment,
        ));
        $this->assertCount(1, $comments);
        $this->assertSame('# trailing', $comments[0]->text);
    }

    public function testMultipleCommentsPreservedInSourceOrder(): void
    {
        $source = "# first\n# second\nfoo: 1\n# third\nbar: 2\n";
        $trivia = $this->allTrivia($source);
        $comments = array_values(array_filter(
            $trivia,
            static fn($t) => $t->type === TriviaType::Comment,
        ));
        $this->assertSame(
            ['# first', '# second', '# third'],
            array_map(static fn($c) => $c->text, $comments),
        );
    }

    public function testBlankLineGroupCountPreserved(): void
    {
        $source = "foo: 1\n\n\n\nbar: 2\n";
        $trivia = $this->allTrivia($source);
        $blanks = array_values(array_filter(
            $trivia,
            static fn($t) => $t->type === TriviaType::BlankLines,
        ));
        $this->assertCount(1, $blanks);
        // Three blank lines between foo and bar (the three "\n\n\n").
        $this->assertSame(3, $blanks[0]->count);
    }

    public function testCommentAndBlankLinesInterleavedPreserveSourceOrder(): void
    {
        $source = "foo: 1\n\n# between\n\nbar: 2\n";
        $trivia = $this->allTrivia($source);
        // Filter out trailing trivia from previous tokens (EOL
        // comments). This fixture has none, all trivia is leading.
        $kinds = array_map(
            static fn($t) => $t->type === TriviaType::Comment ? 'C:' . $t->text : 'B:' . $t->count,
            $trivia,
        );
        $this->assertSame(
            ['B:1', 'C:# between', 'B:1'],
            $kinds,
        );
    }

    public function testEolCommentAppearsAsTrailingTrivia(): void
    {
        $tokens = (new Scanner())->scan("foo: 1  # eol\n");
        // The value Scalar (1) should carry the EOL comment as
        // trailing trivia.
        $valueScalar = null;
        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i]->type === TokenType::Value
                && isset($tokens[$i + 1])
                && $tokens[$i + 1]->type === TokenType::Scalar
            ) {
                $valueScalar = $tokens[$i + 1];
                break;
            }
        }
        $this->assertNotNull($valueScalar);
        $this->assertCount(1, $valueScalar->trailingTrivia);
        $this->assertSame('# eol', $valueScalar->trailingTrivia[0]->text);
    }

    public function testCommentsBeforeAndInBlockSequencePreserved(): void
    {
        $source = "# leading\n- a\n# between\n- b\n";
        $trivia = $this->allTrivia($source);
        $comments = array_values(array_filter(
            $trivia,
            static fn($t) => $t->type === TriviaType::Comment,
        ));
        $this->assertSame(
            ['# leading', '# between'],
            array_map(static fn($c) => $c->text, $comments),
        );
    }
}
