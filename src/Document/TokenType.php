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
 * Lexical token type produced by the scanner.
 *
 * Twenty-two cases covering stream and document boundaries, block and
 * flow mapping/sequence boundaries, key/value/entry indicators,
 * scalars, anchors, aliases, tags, and directives.
 *
 * Public type per Stage 5 §1.3 (Token appears on ParseException via
 * unexpectedToken). Shape is locked from this stage; future scanner
 * refactors may add private fields, no rename or removal of public
 * properties at minor versions.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/05-parser-strategy-2026-06-12.md §2.2
 */
enum TokenType
{
    case StreamStart;
    case StreamEnd;
    case DocumentStart;
    case DocumentEnd;
    case BlockMappingStart;
    case BlockSequenceStart;
    case BlockEntry;
    case BlockEnd;
    case FlowMappingStart;
    case FlowMappingEnd;
    case FlowSequenceStart;
    case FlowSequenceEnd;
    case FlowEntry;
    case Key;
    case Value;
    case Scalar;
    case Anchor;
    case Alias;
    case Tag;
    case Directive;
}
