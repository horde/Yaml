<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document;

use Horde\Yaml\Document\Node\Node;
use Horde\Yaml\Document\Node\ScalarNode;

/**
 * Custom tag handler.
 *
 * The resolver consults a TagRegistry of these for any non-core-schema
 * tag. A handler claims a single tag URI and returns a domain value
 * for fromYaml; toYaml goes the other direction for emission.
 *
 * The handler does not mutate the node's lexical value. That stays
 * for round-trip. It returns the domain value, which the resolver
 * places on the node via setResolvedValue().
 */
interface TagHandler
{
    /**
     * Tag URI this handler claims. Examples:
     *   "!!timestamp", "!php/object", "tag:horde.org,2026:Permission".
     *
     * Both shorthand (`!!`, `!`) and full URI forms are accepted; the
     * resolver will normalise via the document's %TAG handle map
     * before lookup (Stage 12 Y).
     */
    public function tag(): string;

    /**
     * Coerce the loaded scalar node into a domain value.
     *
     * @throws TagHandlerException when the lexical value cannot be
     *     coerced (e.g. malformed timestamp, invalid base64, missing
     *     allowed-class).
     */
    public function fromYaml(ScalarNode $node): mixed;

    /**
     * Convert a domain value back into a Node for emission. Used when
     * a caller constructs an AST programmatically and asks the dumper
     * to emit a tagged value.
     *
     * @throws TagHandlerException when $value is not of a type this
     *     handler can serialise.
     */
    public function toYaml(mixed $value): Node;
}
