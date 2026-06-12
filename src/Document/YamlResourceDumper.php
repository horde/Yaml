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
 * Dump a YamlStream to a PHP stream resource.
 *
 * Per Stage 4 §3.3: writes the emitted bytes via fwrite. Does NOT
 * close the resource; the caller is responsible for that.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/04-public-api-2026-06-12.md §3.3
 */
final class YamlResourceDumper
{
    /**
     * @param resource $resource A PHP stream resource open for writing.
     */
    public function dump(YamlStream $stream, $resource): void
    {
        if (!is_resource($resource)) {
            throw new IoException(
                'Argument is not a stream resource; got ' . get_debug_type($resource),
            );
        }
        $output = (new Emitter())->emit($stream);
        $written = @fwrite($resource, $output);
        if ($written === false || $written < strlen($output)) {
            $error = error_get_last();
            throw new IoException(
                'Failed to write to stream resource'
                    . ($error !== null ? ' (' . $error['message'] . ')' : ''),
            );
        }
    }
}
