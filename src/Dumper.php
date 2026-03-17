<?php

declare(strict_types=1);

/**
 * This package is heavily inspired by the Spyc PHP YAML implementation
 * (http://spyc.sourceforge.net/), and portions are copyright 2005-2006 Chris
 * Wanstrath.
 *
 * @author   Chris Wanstrath <chris@ozmm.org>
 * @author   Chuck Hagenbuch <chuck@horde.org>
 * @author   Mike Naberezny <mike@maintainable.com>
 * @license  http://www.horde.org/licenses/bsd BSD
 * @category Horde
 * @package  Yaml
 */

namespace Horde\Yaml;

use InvalidArgumentException;
use Serializable;
use Traversable;

/**
 * Dump PHP data structures to YAML.
 *
 * Copyright 2005-2026 Chris Wanstrath <chris@ozmm.org>
 * Copyright 2006-2026 Alexey Zakhlestin <indeyets@gmail.com>
 * Copyright 2008-2026 Horde LLC (http://www.horde.org/)
 *
 * @author   Chris Wanstrath <chris@ozmm.org>
 * @author   Chuck Hagenbuch <chuck@horde.org>
 * @author   Mike Naberezny <mike@maintainable.com>
 * @license  http://www.horde.org/licenses/bsd BSD
 * @category Horde
 * @package  Yaml
 */
class Dumper
{
    /**
     * @var array<string, mixed>
     */
    protected array $_options = [];

    /**
     * Dumps PHP array to YAML.
     *
     * The dump method, when supplied with an array, will do its best
     * to convert the array into valid YAML.
     *
     * Options:
     *    `indent`:
     *       number of spaces to indent children (default 2)
     *    `wordwrap`:
     *       wordwrap column number (default 40)
     *
     * @param array<mixed>|Traversable $value  PHP array or traversable object.
     * @param array<string, mixed> $options  Options for dumping.
     *
     * @return string  YAML representation of $value.
     */
    public function dump(array|Traversable $value, array $options = []): string
    {
        // validate & merge default options
        $this->_options = array_merge(
            ['indent' => 2, 'wordwrap' => 40],
            $options
        );

        if (!is_int($this->_options['indent'])) {
            throw new InvalidArgumentException('Indent must be an integer');
        }

        if (!is_int($this->_options['wordwrap'])) {
            throw new InvalidArgumentException('Wordwrap column must be an integer');
        }

        // new YAML document
        $dump = "---\n";

        // iterate through array and yamlize it
        $dump .= $this->_yamlizeArray($value, 0);

        return $dump;
    }

    /**
     * Attempts to convert a key/value array item to YAML.
     *
     * @param string|int $key  The name of the key.
     * @param mixed $value  The value of the item.
     * @param int $indent  The indent of the current node.
     * @param bool $sequence  Is this an entry of a sequence?
     *
     * @return string
     */
    protected function _yamlize(string|int $key, mixed $value, int $indent, bool $sequence = false): string
    {
        if ($value instanceof Serializable) {
            // Dump serializable objects as !php/object::classname
            // serialize_data
            $data = '!php/object::' . get_class($value)
                . ' ' . $value->serialize();
            $string = $this->_dumpNode($key, $data, $indent, $sequence);
        } elseif (is_array($value)) {
            // It has children.  Make it the right kind of item.
            $string = $this->_dumpNode($key, $value, $indent, $sequence);

            // Add the indent.
            $indent += $this->_options['indent'];

            // Yamlize the array.
            $string .= $this->_yamlizeArray($value, $indent);
        } else {
            // No children.
            $string = $this->_dumpNode($key, $value, $indent, $sequence);
        }

        return $string;
    }

    /**
     * Attempts to convert an array to YAML
     *
     * @param array<mixed>|Traversable $array  The array you want to convert.
     * @param int $indent  The indent of the current level.
     *
     * @return string|false
     */
    protected function _yamlizeArray(array|Traversable $array, int $indent): string|false
    {
        if ($array instanceof Traversable) {
            $array = iterator_to_array($array);
        } elseif (!is_array($array)) {
            return false;
        }

        $sequence = array_keys($array) === range(0, count($array) - 1);

        $string = '';
        foreach ($array as $key => $value) {
            $string .= $this->_yamlize($key, $value, $indent, $sequence);
        }
        return $string;
    }

    /**
     * Returns YAML from a key and a value.
     *
     * @param string|int $key  The name of the key.
     * @param mixed $value  The value of the item.
     * @param int $indent  The indent of the current node.
     * @param bool $sequence  Is this an entry of a sequence?
     *
     * @return string
     */
    protected function _dumpNode(string|int $key, mixed $value, int $indent, bool $sequence = false): string
    {
        if (null === $value) {
            $value = '~';
        } elseif (is_array($value)) {
            if (count($value)) {
                $value = '';
            } else {
                $value = '[]';
            }
        } elseif (is_bool($value)) {
            $value = ($value) ? 'true' : 'false';
        } elseif (is_float($value)) {
            if (is_nan($value)) {
                $value = '.NAN';
            } elseif ($value === INF) {
                $value = '.INF';
            } elseif ($value === -INF) {
                $value = '-.INF';
            }
        } elseif (is_string($value)) {
            $literal = false;
            // Do some folding here, for blocks.
            if (strpos($value, "\n") !== false
                || strpos($value, ': ') !== false
                || strpos($value, '- ') !== false) {
                $value = $this->_doLiteralBlock($value, $indent);
                $literal = true;
            } else {
                $value = $this->_fold($value, $indent);
            }


            // Quote strings if necessary, and not folded
            if (!$literal
                && strlen($value)
                && strpos($value, "\n") === false
                && (strchr($value, '#') || $value[0] == '*' || $value[0] == '&')) {
                $value = "'{$value}'";
            }
        }

        $spaces = str_repeat(' ', $indent);

        if ($sequence) {
            // It's a sequence.
            $string = $spaces . '-' . (strlen((string) $value) ? ' ' : '') . $value . "\n";
        } else {
            // It's mapped.
            $string = $spaces . $key . ':' . (strlen((string) $value) ? ' ' : '') . $value . "\n";
        }

        return $string;
    }

    /**
     * Creates a literal block for dumping.
     *
     * @param string $value
     * @param int $indent  The value of the indent.
     *
     * @return string
     */
    protected function _doLiteralBlock(string $value, int $indent): string
    {
        $exploded = explode("\n", $value);
        $newValue = '|';
        if (strlen(end($exploded))) {
            $newValue .= '-';
        } else {
            array_pop($exploded);
            if (!strlen(end($exploded))) {
                $newValue .= '+';
            }
        }
        $indent += $this->_options['indent'];
        $spaces = str_repeat(' ', $indent);
        foreach ($exploded as $line) {
            $newValue .= "\n" . $spaces . trim($line);
        }
        return $newValue;
    }

    /**
     * Folds a string of text, if necessary.
     *
     * @param string $value The string you wish to fold.
     * @param int $indent
     *
     * @return string
     */
    protected function _fold(string $value, int $indent): string
    {
        // Don't do anything if wordwrap is set to 0
        if (!$this->_options['wordwrap']) {
            return $value;
        }

        if (strlen($value) > $this->_options['wordwrap']) {
            $indent += $this->_options['indent'];
            $indent_str = str_repeat(' ', $indent);
            $wrapped = wordwrap($value, $this->_options['wordwrap'], "\n$indent_str");
            $value = '>'
                . (preg_match('/\n$/', $value) ? '' : '-')
                . "\n" . $indent_str . $wrapped;
        }

        return $value;
    }
}
