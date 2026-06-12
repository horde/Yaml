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
 * Load YAML from a PHP stream resource into a YamlStream.
 *
 * Per Stage 4 §3.2: stateless, single load() method. Reads from the
 * resource to EOF using stream_get_contents(); does NOT close the
 * resource.
 *
 * Pass `legacyBooleans: true` to recognise YAML 1.1 boolean spellings
 * (`yes`, `no`, `on`, `off`, etc.). Default is strict YAML 1.2.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/04-public-api-2026-06-12.md §3.2
 */
final class YamlResourceLoader
{
    public function __construct(
        private readonly bool $legacyBooleans = false,
        private readonly ?TagRegistry $tagRegistry = null,
        private readonly bool $recognizeTimestamps = false,
        private readonly ?LeniencyPolicy $policy = null,
    ) {}

    /**
     * @param resource $resource A PHP stream resource (file pointer,
     *                           STDIN, in-memory stream, network
     *                           stream, etc.).
     */
    public function load($resource): YamlStream
    {
        if (!is_resource($resource)) {
            throw new IoException(
                'Argument is not a stream resource; got ' . get_debug_type($resource),
            );
        }
        $contents = stream_get_contents($resource);
        if ($contents === false) {
            throw new IoException(
                'Failed to read from stream resource',
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
