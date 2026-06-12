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
 * Load YAML from a string into a YamlStream.
 *
 * Per Stage 4 §3.1, loaders are concrete classes with specific typed
 * methods; there is no common interface and no static facade. To load
 * from a string, instantiate this class and call load():
 *
 *     $stream = (new YamlStringLoader())->load($yaml);
 *
 * Pass `legacyBooleans: true` to recognise YAML 1.1 boolean spellings
 * (`yes`, `no`, `on`, `off`, etc.). Default is strict YAML 1.2.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/04-public-api-2026-06-12.md §3
 */
final class YamlStringLoader
{
    public function __construct(
        private readonly bool $legacyBooleans = false,
        private readonly ?TagRegistry $tagRegistry = null,
        private readonly bool $recognizeTimestamps = false,
        private readonly ?LeniencyPolicy $policy = null,
    ) {}

    public function load(string $yaml): YamlStream
    {
        return (new Pipeline(
            legacyBooleans: $this->legacyBooleans,
            tagRegistry: $this->tagRegistry,
            recognizeTimestamps: $this->recognizeTimestamps,
            policy: $this->policy,
        ))->parse($yaml);
    }
}
