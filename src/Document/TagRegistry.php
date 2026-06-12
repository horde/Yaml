<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document;

/**
 * Lookup table from tag URI to TagHandler.
 *
 * The default registry is empty. Callers register handlers they want;
 * core schema tags (!!str, !!int, !!float, !!null, !!bool) are NOT
 * routed through the registry. They're handled directly by the
 * resolver per Stage 11 Chapter R. Only non-core tags are looked up
 * here.
 *
 * Pass an instance to a loader's constructor to make it available
 * during resolution.
 */
final class TagRegistry
{
    /** @var array<string, TagHandler> */
    private array $handlers = [];

    public function register(TagHandler $handler): void
    {
        $this->handlers[$handler->tag()] = $handler;
    }

    public function unregister(string $tag): void
    {
        unset($this->handlers[$tag]);
    }

    public function has(string $tag): bool
    {
        return isset($this->handlers[$tag]);
    }

    public function get(string $tag): ?TagHandler
    {
        return $this->handlers[$tag] ?? null;
    }

    /**
     * Look up a handler for a value's PHP type. Used by the emitter
     * to find a handler that knows how to serialise a domain object.
     */
    public function findForValue(mixed $value): ?TagHandler
    {
        if (!is_object($value)) {
            return null;
        }
        foreach ($this->handlers as $handler) {
            try {
                $handler->toYaml($value);
                return $handler;
            } catch (TagHandlerException) {
                continue;
            }
        }
        return null;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return array_keys($this->handlers);
    }
}
