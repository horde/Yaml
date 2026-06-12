<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document\Node;

/**
 * Style of a YAML scalar.
 *
 * Plain is unquoted: `foo`, `42`, `true`. Subjected to YAML 1.2
 * core schema typing in the Resolver.
 *
 * SingleQuoted is `'foo'`. Internal `'` doubled per spec.
 *
 * DoubleQuoted is `"foo"`. Backslash escape sequences allowed.
 *
 * LiteralBlock is `|`. Newlines preserved literally.
 *
 * FoldedBlock is `>`. Newlines folded into spaces per spec.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/03-ast-and-document-model-2026-06-11.md §3
 */
enum ScalarStyle
{
    case Plain;
    case SingleQuoted;
    case DoubleQuoted;
    case LiteralBlock;
    case FoldedBlock;
}
