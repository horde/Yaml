<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;

/**
 * Cross-cutting helpers for policy classes that hold a flat bag of
 * boolean (and small scalar) flags.
 *
 * The expected shape: a final readonly class with public readonly
 * properties, one per flag. The trait provides:
 *
 *   - with() to overlay a partial change immutably
 *   - merge() to combine two policies (other wins on overlap)
 *   - diff() to enumerate flags that differ
 *   - toArray() / describe() for logging
 *
 * The trait makes no assumptions about which flags exist. It
 * reflects on the implementing class's readonly properties at runtime.
 */
trait LeniencyFlagsTrait
{
    /**
     * Return a new instance with the given flags overridden.
     *
     * @param array<string, bool|int|string> $overrides
     */
    public function with(array $overrides): static
    {
        $values = $this->toArray();
        foreach ($overrides as $name => $value) {
            if (!array_key_exists($name, $values)) {
                throw new InvalidArgumentException(
                    "Unknown leniency flag: $name",
                );
            }
            $values[$name] = $value;
        }
        return new static(...$values);
    }

    /**
     * Combine this policy with another. The other's value wins on
     * every flag where the two differ from the strict default.
     */
    public function merge(self $other): static
    {
        $strict = static::strict();
        $strictValues = $strict->toArray();
        $thisValues = $this->toArray();
        $otherValues = $other->toArray();
        $merged = [];
        foreach ($thisValues as $name => $value) {
            $strictValue = $strictValues[$name];
            $otherValue = $otherValues[$name];
            // If other differs from strict, prefer other; else keep this.
            $merged[$name] = $otherValue !== $strictValue ? $otherValue : $value;
        }
        return new static(...$merged);
    }

    /**
     * Return the names and values of every flag where this and other
     * disagree.
     *
     * @return array<string, array{this: mixed, other: mixed}>
     */
    public function diff(self $other): array
    {
        $thisValues = $this->toArray();
        $otherValues = $other->toArray();
        $diff = [];
        foreach ($thisValues as $name => $value) {
            if ($value !== $otherValues[$name]) {
                $diff[$name] = ['this' => $value, 'other' => $otherValues[$name]];
            }
        }
        return $diff;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $reflection = new ReflectionClass($this);
        $values = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isReadOnly()) {
                continue;
            }
            $values[$property->getName()] = $property->getValue($this);
        }
        return $values;
    }

    /**
     * Human-readable summary: name -> "true" or "false" for each flag,
     * sorted, suitable for logs.
     */
    public function describe(): string
    {
        $values = $this->toArray();
        ksort($values);
        $lines = [];
        foreach ($values as $name => $value) {
            $lines[] = sprintf('%s = %s', $name, var_export($value, true));
        }
        return implode("\n", $lines);
    }
}
