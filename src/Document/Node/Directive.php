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
 * A YAML directive (e.g. `%YAML 1.2`, `%TAG !my! tag:example.com,2026:`).
 *
 * Directives are preserved verbatim per Stage 1 §2.1. The parser does
 * not interpret them; the emitter writes them verbatim.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/03-ast-and-document-model-2026-06-11.md §2.10
 */
final class Directive implements Node
{
    use NodeTrait;

    private string $name;
    private string $parameters;

    public function __construct(string $name = '', string $parameters = '')
    {
        $this->name = $name;
        $this->parameters = $parameters;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getParameters(): string
    {
        return $this->parameters;
    }

    public function setParameters(string $parameters): void
    {
        $this->parameters = $parameters;
    }

    /**
     * Parse a `%YAML 1.2`-style directive value into name and
     * parameters. The value passed should be everything after `%`.
     */
    public static function fromValue(string $value): self
    {
        $trimmed = ltrim($value);
        $space = strpos($trimmed, ' ');
        if ($space === false) {
            return new self($trimmed, '');
        }
        return new self(
            substr($trimmed, 0, $space),
            ltrim(substr($trimmed, $space + 1)),
        );
    }
}
