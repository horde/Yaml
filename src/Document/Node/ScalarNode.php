<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document\Node;

use Stringable;
use LogicException;

/**
 * A YAML scalar value.
 *
 * Holds the typed PHP value (string, int, float, bool, or null), the
 * source bytes when round-trip preservation requires retention
 * (rawSource), the scalar style, optional anchor and tag, plus block
 * scalar metadata (chomp mode, indent indicator).
 *
 * Implements Stringable so scalar values work in string contexts
 * naturally without explicit unwrap. Other casts (to int, to float)
 * are not provided; users call value() to get the typed PHP scalar.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/03-ast-and-document-model-2026-06-11.md §2.6
 */
final class ScalarNode implements Node, Stringable
{
    use NodeTrait;

    private string|int|float|bool|null $value;
    private ScalarStyle $style;
    private ?string $rawSource;
    private ?ChompMode $chomp;
    private ?int $indentIndicator;
    private ?string $anchor;
    private ?string $tag;
    private mixed $resolvedValue = null;
    private bool $hasResolvedValue = false;

    public function __construct(
        string|int|float|bool|null $value = null,
        ScalarStyle $style = ScalarStyle::Plain,
        ?string $rawSource = null,
        ?ChompMode $chomp = null,
        ?int $indentIndicator = null,
        ?string $anchor = null,
        ?string $tag = null,
    ) {
        $this->value = $value;
        $this->style = $style;
        $this->rawSource = $rawSource;
        $this->chomp = $chomp;
        $this->indentIndicator = $indentIndicator;
        $this->anchor = $anchor;
        $this->tag = $tag;
    }

    public function getValue(): string|int|float|bool|null
    {
        return $this->value;
    }

    /**
     * Set a new value. Clears rawSource per Stage 3 §4: when the user
     * changes the value, the original source is no longer authoritative.
     * Also clears any resolved value placed by a TagHandler. Once the
     * lexical value changes, the handler's coercion is stale.
     */
    public function setValue(string|int|float|bool|null $value): void
    {
        $this->value = $value;
        $this->rawSource = null;
        $this->resolvedValue = null;
        $this->hasResolvedValue = false;
    }

    /**
     * Return the resolved domain value placed on this node by a
     * TagHandler. Throws if no handler set one. Call hasResolvedValue()
     * first.
     */
    public function getResolvedValue(): mixed
    {
        if (!$this->hasResolvedValue) {
            throw new LogicException(
                'No resolved value on this scalar; check hasResolvedValue() first',
            );
        }
        return $this->resolvedValue;
    }

    public function hasResolvedValue(): bool
    {
        return $this->hasResolvedValue;
    }

    /**
     * Place a resolved domain value on this node. Used by the resolver
     * after a TagHandler has coerced the scalar. The lexical value and
     * rawSource are preserved for round-trip emission.
     */
    public function setResolvedValue(mixed $value): void
    {
        $this->resolvedValue = $value;
        $this->hasResolvedValue = true;
    }

    public function clearResolvedValue(): void
    {
        $this->resolvedValue = null;
        $this->hasResolvedValue = false;
    }

    public function getStyle(): ScalarStyle
    {
        return $this->style;
    }

    public function setStyle(ScalarStyle $style): void
    {
        $this->style = $style;
    }

    public function getRawSource(): ?string
    {
        return $this->rawSource;
    }

    /**
     * Package-internal: set rawSource. Called by the Resolver when
     * stamping source bytes for round-trip preservation.
     */
    public function setRawSource(?string $rawSource): void
    {
        $this->rawSource = $rawSource;
    }

    public function getChomp(): ?ChompMode
    {
        return $this->chomp;
    }

    public function setChomp(?ChompMode $chomp): void
    {
        $this->chomp = $chomp;
    }

    public function getIndentIndicator(): ?int
    {
        return $this->indentIndicator;
    }

    public function setIndentIndicator(?int $indentIndicator): void
    {
        $this->indentIndicator = $indentIndicator;
    }

    public function getAnchor(): ?string
    {
        return $this->anchor;
    }

    public function setAnchor(?string $anchor): void
    {
        $this->anchor = $anchor;
    }

    public function getTag(): ?string
    {
        return $this->tag;
    }

    public function setTag(?string $tag): void
    {
        $this->tag = $tag;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
