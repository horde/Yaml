<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document;

use Horde\Yaml\Document\Node\ChompMode;
use Horde\Yaml\Document\Node\MapStyle;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\Node\SequenceStyle;

/**
 * A lexical token produced by the scanner and consumed by the parser.
 *
 * Final readonly. Tokens are conceptually values:
 * produced once, consumed once, never mutated.
 *
 * Public type (appears on ParseException via
 * unexpectedToken).
 *
 * Field semantics:
 *
 *   type             the lexical category (see TokenType).
 *   value            payload for tokens that carry one (Scalar,
 *                    Anchor, Alias, Tag, Directive); null otherwise.
 *   line, column     1-based source position of the first byte of
 *                    this token.
 *   style            the scalar/map/sequence style as observed by
 *                    the scanner; null when the
 *                    token type doesn't carry style.
 *   chomp            block-scalar chomp indicator; null for non-block
 *                    scalars and for non-scalar tokens.
 *   indentIndicator  explicit block-scalar indent indicator (1..9);
 *                    null for implicit indent or non-block scalars.
 *   leadingTrivia    TriviaToken[] for whatever appeared between the
 *                    previous structural token and this one.
 *   trailingTrivia   TriviaToken[] for whatever appears after this
 *                    token up to the next structural boundary
 *                    (typically just an EOL comment).
 *
 */
final readonly class Token
{
    /**
     * @param list<TriviaToken> $leadingTrivia
     * @param list<TriviaToken> $trailingTrivia
     */
    public function __construct(
        public TokenType $type,
        public int $line,
        public int $column,
        public ?string $value = null,
        public ScalarStyle|MapStyle|SequenceStyle|null $style = null,
        public ?ChompMode $chomp = null,
        public ?int $indentIndicator = null,
        public array $leadingTrivia = [],
        public array $trailingTrivia = [],
        public ?string $rawSource = null,
    ) {}
}
