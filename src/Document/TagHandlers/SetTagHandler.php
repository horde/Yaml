<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document\TagHandlers;

use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\Node;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\TagHandler;
use Horde\Yaml\Document\TagHandlerException;

/**
 * Handler for `!!set`: a mapping where every value is null,
 * representing a de-duplicated unordered collection.
 *
 * Per Stage 12 §X path A: the resolved domain value is a PHP
 * `array<string, null>` keyed by member name. Round-trip preserves
 * the source mapping form (typically `? member` lines).
 *
 * The handler claims `tag:yaml.org,2002:set`. It is only invoked
 * when the resolver encounters a scalar node tagged `!!set`. But
 * sets are mappings, not scalars. The current registry signature
 * works on ScalarNode; for collection tags we treat the call as a
 * no-op (the lexical mapping is already correct, the resolved view
 * surfaces the structure naturally). A dedicated path for mapping
 * tags lives outside the per-scalar resolver loop.
 *
 * In practice, callers wanting `!!set` semantics consult
 * MapNode::resolved() directly; the array<string, null> shape falls
 * out for free since each entry's value is null.
 */
final class SetTagHandler implements TagHandler
{
    public const TAG = 'tag:yaml.org,2002:set';

    public function tag(): string
    {
        return self::TAG;
    }

    public function fromYaml(ScalarNode $node): mixed
    {
        // The resolver only routes scalar nodes through here. Sets
        // appear as mappings; this method is invoked only if a caller
        // ever stamps !!set on a scalar (which is illegal). Throw so
        // the misuse is visible.
        throw new TagHandlerException(
            '!!set requires a mapping node, not a scalar',
        );
    }

    public function toYaml(mixed $value): Node
    {
        if (!is_array($value)) {
            throw new TagHandlerException(
                'SetTagHandler expects array, got ' . get_debug_type($value),
            );
        }
        // Every value must be null; otherwise it is not a set.
        foreach ($value as $member => $v) {
            if ($v !== null) {
                throw new TagHandlerException(
                    'Set member "' . $member . '" has non-null value',
                );
            }
        }
        // Caller assembles the MapNode; this method is rarely used
        // and intentionally throws if asked for a scalar form.
        throw new TagHandlerException(
            '!!set serialisation is performed by the emitter via MapNode tag, '
                . 'not by this handler',
        );
    }

    /**
     * Validate that a MapNode is set-shaped (every entry value is
     * null or empty scalar). Useful for callers building sets
     * programmatically.
     */
    public static function isSetShaped(MapNode $node): bool
    {
        foreach ($node->entries() as $entry) {
            $value = $entry->getValue();
            if (!$value instanceof ScalarNode) {
                return false;
            }
            $v = $value->getValue();
            if ($v !== null && $v !== '') {
                return false;
            }
        }
        return true;
    }
}
