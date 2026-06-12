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
 * Round-trip metadata for flow-style nodes.
 *
 * Per Stage 3 §3 and Stage 2 §9.1, multi-line flow style preserves its
 * inner whitespace structure as opaque data on the flow node rather
 * than reconstructing whitespace from per-item trivia.
 *
 * The exact shape of FlowFormat is refined in phase I.03 when the
 * parser actually captures multi-line flow whitespace. For now this
 * class is a placeholder: a single-line flag and raw source text
 * sufficient to satisfy the AST contract.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/02-roundtrip-semantics-2026-06-11.md §3.5
 */
final readonly class FlowFormat
{
    /**
     * @param bool   $singleLine Whether the flow node was on one line in source.
     * @param string $rawText    The exact bytes between `[` `]` (or `{` `}`)
     *                           in source, used for verbatim round-trip when
     *                           the node is not mutated.
     */
    public function __construct(
        public bool $singleLine,
        public string $rawText = '',
    ) {}
}
