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
 * Named per-rule policy controlling the document layer's deviations
 * from strict YAML 1.2.
 *
 * Each flag names exactly one tolerated deviation. The default
 * constructor returns the strict baseline (every flag false). Use
 * one of the curated factory methods to obtain a preset:
 *
 *   - LeniencyPolicy::strictYaml12()  every flag false
 *   - LeniencyPolicy::hordeCompat()   current behaviour: tolerates
 *                                     what real .horde.yml files do
 *   - LeniencyPolicy::tolerant()      accept everything possible
 *
 * Flags can be overridden individually via with():
 *
 *   $policy = LeniencyPolicy::hordeCompat()
 *       ->with(['acceptDuplicateYamlDirective' => false]);
 *
 * Each flag's effect is documented in doc/LENIENCY.md.
 */
final class LeniencyPolicy
{
    use LeniencyFlagsTrait;

    public function __construct(
        public readonly bool $acceptDuplicateYamlDirective = false,
        public readonly bool $acceptMalformedYamlDirectiveArguments = false,
        public readonly bool $acceptDirectiveOnlyDocument = false,
        public readonly bool $acceptUnindentedQuotedContinuation = false,
        public readonly bool $acceptQuotedScalarSpanningMarkers = false,
        public readonly bool $acceptFlowSequenceAsKey = false,
        public readonly bool $acceptUnindentedTagBody = false,
        /**
         * Tolerate `!handle!suffix` shorthand whose `!handle!` was
         * never declared by a `%TAG` directive in scope (e.g. a
         * directive applied to a previous document). Strict YAML 1.2
         * (§6.8.2.4) treats this as an error: the shorthand cannot
         * be resolved. With this flag on, the tag is left unexpanded
         * and resolution falls back to the registry / default
         * behaviour. yaml-test-suite QLJ7.
         */
        public readonly bool $acceptUndefinedNamedTagHandle = false,
        /**
         * Umbrella for whitespace and format quirks (trailing
         * whitespace, whitespace-only lines, redundant inter-token
         * gap). The Stage 15 AY chapter splits this into individual
         * flags as each case is reviewed; until then it is one
         * setting.
         */
        public readonly bool $tolerateWhitespaceQuirks = false,
    ) {}

    /**
     * Strict YAML 1.2: reject every deviation. Useful for callers
     * that must produce spec-compliant output or validate input.
     */
    public static function strictYaml12(): self
    {
        return new self();
    }

    /**
     * Alias of strictYaml12() used internally by the trait's merge()
     * to obtain the all-false baseline. Public mostly for symmetry.
     */
    public static function strict(): self
    {
        return self::strictYaml12();
    }

    /**
     * Default for the existing loaders. Tolerates the deviations
     * that real-world .horde.yml files exercise. Switching to a
     * stricter policy will reject input that today loads cleanly.
     */
    public static function hordeCompat(): self
    {
        return new self(
            acceptDuplicateYamlDirective: true,
            acceptMalformedYamlDirectiveArguments: true,
            acceptDirectiveOnlyDocument: true,
            acceptUnindentedQuotedContinuation: true,
            acceptQuotedScalarSpanningMarkers: true,
            acceptFlowSequenceAsKey: true,
            acceptUnindentedTagBody: true,
            acceptUndefinedNamedTagHandle: true,
            tolerateWhitespaceQuirks: true,
        );
    }

    /**
     * Maximum tolerance. Same as hordeCompat() today; the difference
     * widens as new flags are added in future stages.
     */
    public static function tolerant(): self
    {
        return self::hordeCompat();
    }
}
