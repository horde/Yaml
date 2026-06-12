<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document\Parser;

use Horde\Yaml\Document\LeniencyPolicy;
use Horde\Yaml\Document\Node\AliasNode;
use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\Node;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\Node\SequenceItem;
use Horde\Yaml\Document\ParseException;
use Horde\Yaml\Document\TagRegistry;
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\YamlDocument;
use Horde\Yaml\Document\YamlStream;

/**
 * Walks an untyped YamlStream and applies YAML 1.2 core schema typing
 * to plain scalars. Quoted and block scalars stay strings unless they
 * carry an explicit core-schema tag.
 *
 * Sets `rawSource` on ScalarNode whenever the typed value would not
 * re-emit identically (e.g. `0xFF` -> 255, `1e2` -> 100.0, `True` ->
 * true). Plain scalars whose canonical re-emit matches their source
 * bytes (most common case) leave `rawSource` null.
 *
 * Tag handling is rudimentary in B.04: explicit core tags
 * (!!str, !!int, !!bool, !!null, !!float) override regex resolution.
 * Custom tags (!Foo) leave the value as the raw string.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/05-parser-strategy-2026-06-12.md §4
 */
final class Resolver
{
    private const NULL_PATTERN = '/^(?:null|Null|NULL|~|)$/';
    private const TRUE_PATTERN = '/^(?:true|True|TRUE)$/';
    private const FALSE_PATTERN = '/^(?:false|False|FALSE)$/';
    private const LEGACY_TRUE_PATTERN = '/^(?:y|Y|yes|Yes|YES|on|On|ON|true|True|TRUE)$/';
    private const LEGACY_FALSE_PATTERN = '/^(?:n|N|no|No|NO|off|Off|OFF|false|False|FALSE)$/';
    private const INT_DEC_PATTERN = '/^[-+]?[0-9]+$/';
    private const INT_OCT_PATTERN = '/^0o[0-7]+$/';
    private const INT_HEX_PATTERN = '/^0x[0-9a-fA-F]+$/';
    private const FLOAT_PATTERN = '/^[-+]?(?:\.[0-9]+|[0-9]+(?:\.[0-9]*)?)(?:[eE][-+]?[0-9]+)?$/';
    private const INF_PATTERN = '/^[-+]?\.(?:inf|Inf|INF)$/';
    private const NAN_PATTERN = '/^\.(?:nan|NaN|NAN)$/';

    /** @var array<string, string> Handle prefixes for the current document */
    private array $currentTagHandles = [];

    /**
     * @param bool $legacyBooleans When true, recognise YAML 1.1 boolean
     *     spellings (`yes`, `no`, `on`, `off`, `y`, `n` and their case
     *     variants) as booleans on plain scalars. Quoted scalars are
     *     unaffected. Default false (strict YAML 1.2 per Stage 1 §B9).
     * @param ?TagRegistry $tagRegistry Custom-tag handler registry.
     *     Core schema tags (!!str, !!int, !!float, !!null, !!bool)
     *     are handled directly; non-core tags are looked up here.
     *     If a handler claims the tag, the resolved domain value is
     *     placed on the node alongside the lexical value.
     * @param bool $recognizeTimestamps When true, plain scalars
     *     matching ISO 8601 date/datetime are coerced to
     *     DateTimeImmutable via TimestampTagHandler. Default false:
     *     timestamps stay strings unless an explicit `!!timestamp`
     *     tag is present and the registry has a handler.
     */
    public function __construct(
        private readonly bool $legacyBooleans = false,
        private readonly ?TagRegistry $tagRegistry = null,
        private readonly bool $recognizeTimestamps = false,
        private readonly ?LeniencyPolicy $policy = null,
    ) {}

    public function resolve(YamlStream $stream): void
    {
        foreach ($stream->getDocuments() as $doc) {
            $this->resolveDocument($doc);
        }
    }

    private function resolveDocument(YamlDocument $doc): void
    {
        // Seed default handles per YAML 1.2: `!` -> `!`, `!!` ->
        // `tag:yaml.org,2002:`. User %TAG directives override these.
        $this->currentTagHandles = ['!' => '!', '!!' => 'tag:yaml.org,2002:'];
        foreach ($doc->getTagHandles() as $handle => $prefix) {
            $this->currentTagHandles[$handle] = $prefix;
        }
        $root = $doc->root();
        if ($root !== null) {
            $this->resolveNode($root);
        }
    }

    /**
     * Expand a tag's shorthand handle (`!!`, `!`, `!foo!`) to its full
     * URI per the document's %TAG handle map. Returns the tag string
     * unchanged if it does not start with a handle, or if the handle
     * isn't registered (the caller will then treat it as a verbatim
     * tag and fall back to the registry / default behaviour).
     */
    private function expandTagShorthand(string $tag): string
    {
        if ($tag === '' || $tag[0] !== '!') {
            return $tag;
        }
        // Verbatim form `!<...>` is already-fully-qualified.
        if (str_starts_with($tag, '!<') && str_ends_with($tag, '>')) {
            return substr($tag, 2, -1);
        }
        // Try named handles `!foo!suffix` first.
        if (preg_match('/^(![A-Za-z0-9_-]+!)(.*)$/', $tag, $m)) {
            $handle = $m[1];
            if (isset($this->currentTagHandles[$handle])) {
                return $this->currentTagHandles[$handle] . $m[2];
            }
            // Per §6.8.2.4 / §6.9.1.2: a `!handle!` shorthand may
            // only refer to a handle declared by a `%TAG` directive
            // in scope for THIS document. Directives only carry over
            // to the document immediately following them; using a
            // shorthand from a previous document's directive is a
            // resolution error (yaml-test-suite QLJ7).
            if ($this->policy !== null && !$this->policy->acceptUndefinedNamedTagHandle) {
                throw new ParseException(sprintf(
                    'Undefined named tag handle "%s" (no `%%TAG` directive '
                        . 'in scope; allow with acceptUndefinedNamedTagHandle)',
                    $handle,
                ));
            }
            return $tag;
        }
        // Secondary handle `!!suffix`.
        if (str_starts_with($tag, '!!')) {
            $handle = '!!';
            if (isset($this->currentTagHandles[$handle])) {
                return $this->currentTagHandles[$handle] . substr($tag, 2);
            }
            return $tag;
        }
        // Primary handle `!suffix` (suffix may be empty).
        if (str_starts_with($tag, '!')) {
            $handle = '!';
            if (isset($this->currentTagHandles[$handle])) {
                return $this->currentTagHandles[$handle] . substr($tag, 1);
            }
        }
        return $tag;
    }

    private function resolveNode(Node $node): void
    {
        // Validate any tag carried by a non-scalar node (Map / Seq /
        // Alias). Scalar nodes get their tag expanded inside
        // resolveScalar() during typing. The expand call enforces
        // QLJ7-style "shorthand handle is in scope" gates even for
        // structural nodes.
        if (
            !$node instanceof ScalarNode
            && method_exists($node, 'getTag')
        ) {
            $tag = $node->getTag();
            if ($tag !== null && $tag !== '' && $tag[0] === '!') {
                $this->expandTagShorthand($tag);
            }
        }

        if ($node instanceof ScalarNode) {
            $this->resolveScalar($node);
            return;
        }

        if ($node instanceof MapNode) {
            foreach ($node->entries() as $entry) {
                $this->resolveNode($entry);
            }
            return;
        }

        if ($node instanceof MapEntry) {
            $this->resolveNode($node->getKey());
            $this->resolveNode($node->getValue());
            return;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->items() as $item) {
                $this->resolveNode($item);
            }
            return;
        }

        if ($node instanceof SequenceItem) {
            $value = $node->getValue();
            if ($value !== null) {
                $this->resolveNode($value);
            }
            return;
        }

        if ($node instanceof AliasNode) {
            return;
        }
    }

    private function resolveScalar(ScalarNode $node): void
    {
        $tag = $node->getTag();
        $sourceBytes = (string) $node->getValue();
        $style = $node->getStyle();

        // Explicit core tag overrides style and regex.
        if ($tag !== null) {
            $expanded = $this->expandTagShorthand($tag);
            $this->applyTag($node, $expanded, $sourceBytes);
            return;
        }

        // Quoted and block scalars: stay as strings, no transformation.
        // The parser already stored the unescaped/folded value, and the
        // rawSource (for block scalars) was captured at parse time.
        // Calling setValue would clear rawSource, so we leave the node
        // as-is.
        if ($style !== ScalarStyle::Plain) {
            return;
        }

        // Plain scalars: apply core schema.
        $this->resolvePlain($node, $sourceBytes);
    }

    private function resolvePlain(ScalarNode $node, string $source): void
    {
        // Null
        if (preg_match(self::NULL_PATTERN, $source)) {
            $node->setValue(null);
            $node->setRawSource($source === 'null' || $source === '' ? null : $source);
            return;
        }

        // Bool
        $truePattern = $this->legacyBooleans
            ? self::LEGACY_TRUE_PATTERN
            : self::TRUE_PATTERN;
        $falsePattern = $this->legacyBooleans
            ? self::LEGACY_FALSE_PATTERN
            : self::FALSE_PATTERN;
        if (preg_match($truePattern, $source)) {
            $node->setValue(true);
            $node->setRawSource($source === 'true' ? null : $source);
            return;
        }
        if (preg_match($falsePattern, $source)) {
            $node->setValue(false);
            $node->setRawSource($source === 'false' ? null : $source);
            return;
        }

        // Integer (decimal)
        if (preg_match(self::INT_DEC_PATTERN, $source)) {
            $value = (int) $source;
            $node->setValue($value);
            $node->setRawSource((string) $value === $source ? null : $source);
            return;
        }

        // Integer (octal)
        if (preg_match(self::INT_OCT_PATTERN, $source)) {
            $value = octdec(substr($source, 2));
            $node->setValue((int) $value);
            $node->setRawSource($source);
            return;
        }

        // Integer (hex)
        if (preg_match(self::INT_HEX_PATTERN, $source)) {
            $value = hexdec(substr($source, 2));
            $node->setValue((int) $value);
            $node->setRawSource($source);
            return;
        }

        // Float (special: infinity)
        if (preg_match(self::INF_PATTERN, $source)) {
            $node->setValue($source[0] === '-' ? -INF : INF);
            $node->setRawSource($source);
            return;
        }

        // Float (special: NaN)
        if (preg_match(self::NAN_PATTERN, $source)) {
            $node->setValue(NAN);
            $node->setRawSource($source);
            return;
        }

        // Float (general)
        if (preg_match(self::FLOAT_PATTERN, $source)) {
            $value = (float) $source;
            $canonical = $this->canonicalFloat($value);
            $node->setValue($value);
            $node->setRawSource($canonical === $source ? null : $source);
            return;
        }

        // Implicit timestamp recognition (opt-in). Coerces ISO 8601
        // date/datetime forms to DateTimeImmutable while keeping the
        // lexical value for round-trip.
        if ($this->recognizeTimestamps
            && \Horde\Yaml\Document\TagHandlers\TimestampTagHandler::isTimestampLike($source)
        ) {
            $node->setValue($source);
            $node->setRawSource(null);
            try {
                $dt = \Horde\Yaml\Document\TagHandlers\TimestampTagHandler::parseTimestamp($source);
                $node->setResolvedValue($dt);
            } catch (\Horde\Yaml\Document\TagHandlerException) {
                // Lexical match but PHP couldn't construct the
                // DateTimeImmutable (e.g. illegal calendar date).
                // Fall through to the string fallback.
            }
            if ($node->hasResolvedValue()) {
                return;
            }
        }

        // Fallback: string. Plain string source with no
        // transformation. Preserve any rawSource the parser already
        // stored (multi-line plain scalars per YAML 1.2 §7.3.3 fold
        // newlines into spaces in the value but retain the source
        // bytes for round-trip).
        $existingRaw = $node->getRawSource();
        $node->setValue($source);
        if ($existingRaw !== null && $existingRaw !== $source) {
            $node->setRawSource($existingRaw);
        } else {
            $node->setRawSource(null);
        }
    }

    private function applyTag(ScalarNode $node, string $tag, string $source): void
    {
        // Recognise core-schema tags and the URI form. Custom tags
        // leave the value as the raw string.
        switch ($tag) {
            case '!!str':
            case 'tag:yaml.org,2002:str':
                $node->setValue($source);
                $node->setRawSource(null);
                return;

            case '!!int':
            case 'tag:yaml.org,2002:int':
                if (preg_match(self::INT_DEC_PATTERN, $source)) {
                    $node->setValue((int) $source);
                    $node->setRawSource(null);
                    return;
                }
                if (preg_match(self::INT_OCT_PATTERN, $source)) {
                    $node->setValue((int) octdec(substr($source, 2)));
                    $node->setRawSource($source);
                    return;
                }
                if (preg_match(self::INT_HEX_PATTERN, $source)) {
                    $node->setValue((int) hexdec(substr($source, 2)));
                    $node->setRawSource($source);
                    return;
                }
                throw new ParseException(sprintf(
                    'Tag !!int requires an integer value; got %s',
                    $this->summariseValue($source),
                ));

            case '!!bool':
            case 'tag:yaml.org,2002:bool':
                if (preg_match(self::TRUE_PATTERN, $source)) {
                    $node->setValue(true);
                    $node->setRawSource(null);
                    return;
                }
                if (preg_match(self::FALSE_PATTERN, $source)) {
                    $node->setValue(false);
                    $node->setRawSource(null);
                    return;
                }
                throw new ParseException(sprintf(
                    'Tag !!bool requires a boolean value; got %s',
                    $this->summariseValue($source),
                ));

            case '!!null':
            case 'tag:yaml.org,2002:null':
                if ($source === ''
                    || preg_match(self::NULL_PATTERN, $source)
                ) {
                    $node->setValue(null);
                    $node->setRawSource(null);
                    return;
                }
                throw new ParseException(sprintf(
                    'Tag !!null requires an empty or null value; got %s',
                    $this->summariseValue($source),
                ));

            case '!!float':
            case 'tag:yaml.org,2002:float':
                if (preg_match(self::INF_PATTERN, $source)) {
                    $node->setValue($source[0] === '-' ? -INF : INF);
                    $node->setRawSource(null);
                    return;
                }
                if (preg_match(self::NAN_PATTERN, $source)) {
                    $node->setValue(NAN);
                    $node->setRawSource(null);
                    return;
                }
                if (preg_match(self::FLOAT_PATTERN, $source)
                    || preg_match(self::INT_DEC_PATTERN, $source)
                ) {
                    $node->setValue((float) $source);
                    $node->setRawSource(null);
                    return;
                }
                throw new ParseException(sprintf(
                    'Tag !!float requires a numeric value; got %s',
                    $this->summariseValue($source),
                ));

            default:
                // Custom tag: ask the registry, if any. Try the
                // expanded form first; fall back to the shorthand
                // (handlers may register either form).
                $handler = $this->tagRegistry?->get($tag);
                if ($handler === null) {
                    $original = $node->getTag();
                    if ($original !== null && $original !== $tag) {
                        $handler = $this->tagRegistry?->get($original);
                    }
                }
                if ($handler !== null) {
                    $node->setValue($source);
                    $node->setRawSource(null);
                    $node->setResolvedValue($handler->fromYaml($node));
                    return;
                }
                // No handler: leave the value as the raw source string.
                $node->setValue($source);
                $node->setRawSource(null);
                return;
        }
    }

    /**
     * Render a short, safe preview of a value for error messages.
     */
    private function summariseValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '(empty)';
        }
        if (strlen($trimmed) > 40) {
            $trimmed = substr($trimmed, 0, 37) . '...';
        }
        return '"' . $trimmed . '"';
    }

    /**
     * Return the canonical PHP-emitted form of a float so the resolver
     * can decide whether to retain rawSource. PHP's default
     * serialize_precision=-1 (since 7.1) produces the shortest
     * representation that round-trips through (float) cast.
     */
    private function canonicalFloat(float $value): string
    {
        if (is_infinite($value)) {
            return $value < 0 ? '-.inf' : '.inf';
        }
        if (is_nan($value)) {
            return '.nan';
        }
        return (string) $value;
    }
}
