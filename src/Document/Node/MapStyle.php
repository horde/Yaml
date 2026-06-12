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
 * Style of a YAML mapping.
 *
 * Block is `key: value` per line.
 * Flow is `{key: value, ...}` inline.
 */
enum MapStyle
{
    case Block;
    case Flow;
}
