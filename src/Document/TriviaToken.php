<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document;

/**
 * One trivia token: either a standalone comment line or a run of
 * blank lines, attached to a structural Token's leadingTrivia or
 * trailingTrivia list.
 *
 * Both `text` (for comments) and `count` (for blank-line runs) are
 * provided unconditionally; whichever applies is determined by
 * `type`. The unused field carries a sensible default ("" or 0)
 * rather than null so static analysis stays clean.
 *
 * `gap` carries the leading-whitespace bytes between the preceding
 * structural token and this trivia. For an EOL comment that means
 * the spaces/tabs between the value and the `#`; for a standalone
 * comment line that means the indentation before the `#`. Used by
 * the emitter to reproduce the original spacing byte-for-byte.
 *
 * Final readonly per Stage 5 Q11.1.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/05-parser-strategy-2026-06-12.md §2.3
 */
final readonly class TriviaToken
{
    public function __construct(
        public TriviaType $type,
        public string $text,
        public int $count,
        public int $line,
        public int $column,
        public string $gap = '',
    ) {}

    /**
     * Convenience constructor for a comment trivia token.
     *
     * @param string $text Comment text including the leading `#`.
     * @param string $gap  Whitespace between the preceding token and `#`.
     */
    public static function comment(
        string $text,
        int $line,
        int $column,
        string $gap = '',
    ): self {
        return new self(TriviaType::Comment, $text, 0, $line, $column, $gap);
    }

    /**
     * Convenience constructor for a blank-lines trivia token.
     */
    public static function blankLines(int $count, int $line, int $column): self
    {
        return new self(TriviaType::BlankLines, '', $count, $line, $column);
    }
}
