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
 * Dump a YamlStream to a file path.
 *
 * Per Stage 6 §9: atomic via temp file + rename. The temp path lives
 * in the target's directory so the rename stays intra-filesystem.
 * Cross-filesystem rename failure throws IoException rather than
 * silently degrading to non-atomic copy-and-delete.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/06-emitter-strategy-2026-06-12.md §9
 */
final class YamlFileDumper
{
    public function dump(YamlStream $stream, string $path): void
    {
        $output = (new Emitter())->emit($stream);
        $dir = dirname($path);
        $tempPath = $dir . '/' . basename($path) . '.tmp.' . bin2hex(random_bytes(8));

        $bytes = @file_put_contents($tempPath, $output);
        if ($bytes === false) {
            $error = error_get_last();
            throw new IoException(
                "Failed to write temp file: $tempPath"
                    . ($error !== null ? ' (' . $error['message'] . ')' : ''),
                $tempPath,
            );
        }

        if (!@rename($tempPath, $path)) {
            $error = error_get_last();
            // Best-effort cleanup of the temp file.
            @unlink($tempPath);
            throw new IoException(
                "Failed to rename $tempPath to $path"
                    . ($error !== null ? ' (' . $error['message'] . ')' : '')
                    . ' (cross-filesystem temp/target setups are not supported)',
                $path,
            );
        }
    }
}
