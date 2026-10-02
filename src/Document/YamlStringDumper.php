<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document;

use Horde\Yaml\Document\Emitter\Emitter;

/**
 * Dump a YamlStream to a string.
 *
 * Dumpers are concrete classes with specific typed
 * methods; there is no common interface and no static facade.
 * Stateless. No constructor parameters.
 *
 */
final class YamlStringDumper
{
    public function dump(YamlStream $stream): string
    {
        return (new Emitter())->emit($stream);
    }
}
