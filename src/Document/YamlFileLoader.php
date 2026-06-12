<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document;

use Horde\Yaml\Document\Parser\Pipeline;

/**
 * Load YAML from a file path into a YamlStream.
 *
 * Per Stage 4 §3.2: stateless, no constructor parameters, single
 * load() method. Throws FileNotFoundException if the path does not
 * exist or cannot be read; IoException for other read failures.
 *
 * Pass `legacyBooleans: true` to recognise YAML 1.1 boolean spellings
 * (`yes`, `no`, `on`, `off`, etc.). Default is strict YAML 1.2.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/04-public-api-2026-06-12.md §3.2
 */
final class YamlFileLoader
{
    public function __construct(
        private readonly bool $legacyBooleans = false,
        private readonly ?TagRegistry $tagRegistry = null,
        private readonly bool $recognizeTimestamps = false,
        private readonly ?LeniencyPolicy $policy = null,
    ) {}

    public function load(string $path): YamlStream
    {
        if (!file_exists($path)) {
            throw new FileNotFoundException(
                "File not found: $path",
                $path,
            );
        }
        if (!is_readable($path)) {
            throw new FileNotFoundException(
                "File not readable: $path",
                $path,
            );
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            $error = error_get_last();
            throw new IoException(
                "Failed to read file: $path"
                    . ($error !== null ? ' (' . $error['message'] . ')' : ''),
                $path,
            );
        }
        return (new Pipeline(
            legacyBooleans: $this->legacyBooleans,
            tagRegistry: $this->tagRegistry,
            recognizeTimestamps: $this->recognizeTimestamps,
            policy: $this->policy,
        ))->parse($contents);
    }
}
