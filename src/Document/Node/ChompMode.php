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
 * Chomp mode for block scalars (`|` and `>`).
 *
 * Clip is the default. A single trailing newline is kept and any
 * additional trailing blank lines are stripped.
 *
 * Strip corresponds to `-`. The trailing newline and any trailing
 * blanks are stripped.
 *
 * Keep corresponds to `+`. The trailing newline and all trailing
 * blanks are preserved as part of the value.
 *
 * Only meaningful for ScalarStyle::LiteralBlock and FoldedBlock.
 *
 * @see https://yaml.org/spec/1.2.2/#8112-block-chomping-indicator
 */
enum ChompMode
{
    case Clip;
    case Strip;
    case Keep;
}
