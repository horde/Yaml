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
 * Per Stage 4 §3.1, dumpers are concrete classes with specific typed
 * methods; there is no common interface and no static facade.
 * Stateless. No constructor parameters.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/04-public-api-2026-06-12.md §3.3
 */
final class YamlStringDumper
{
    public function dump(YamlStream $stream): string
    {
        return (new Emitter())->emit($stream);
    }
}
