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
 * Type of a trivia token attached to a structural Token's
 * leadingTrivia or trailingTrivia list.
 *
 * Comment is a `#` line. The text lives in TriviaToken::$text.
 *
 * BlankLines is a run of one or more blank lines. The count is in
 * TriviaToken::$count.
 *
 */
enum TriviaType
{
    case Comment;
    case BlankLines;
}
