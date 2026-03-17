<?php

declare(strict_types=1);

/**
 * Horde YAML package
 *
 * This package is heavily inspired by the Spyc PHP YAML
 * implementation (http://spyc.sourceforge.net/), and portions are
 * copyright 2005-2006 Chris Wanstrath.
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
use RuntimeException;
use Traversable;

/**
 * Horde YAML parser.
 *
 * This class can be used to read a YAML file and convert its contents
 * into a PHP array. The native PHP parser supports a limited
 * subsection of the YAML spec, but if the syck extension is present,
 * that will be used for parsing.
 *
 * Copyright 2005-2026 Chris Wanstrath <chris@ozmm.org>
 * Copyright 2006-2026 Alexey Zakhlestin <indeyets@gmail.com>
 * Copyright 2008-2026 Horde LLC (http://www.horde.org/)
 *
 * @category Horde
 * @package  Yaml
 */
class Yaml
{
    /**
     * Callback used for alternate YAML loader, typically exported
     * by a faster PHP extension.  This function's first argument
     * must accept a string with YAML content.
     *
     * @var callable|string
     */
    public static mixed $loadfunc = 'syck_load';

    /**
     * Callback used for alternate YAML dumper, typically exported
     * by a faster PHP extension.  This function's first argument
     * must accept a mixed variable to be dumped.
     *
     * @var callable|string
     */
    public static mixed $dumpfunc = 'syck_dump';

    /**
     * Whitelist of classes that can be instantiated automatically
     * when loading YAML docs that include serialized PHP objects.
     *
     * @var array<int, string>
     */
    public static array $allowedClasses = ['ArrayObject'];

    /**
     * Load a string containing YAML and parse it into a PHP array.
     * Returns an empty array on failure.
     *
     * @param  string  $yaml   String containing YAML
     * @return array<string|int, mixed>  PHP array representation of YAML content
     */
    public static function load(string $yaml): array
    {
        if (!strlen($yaml)) {
            $msg = 'YAML to parse must be a string and cannot be empty.';
            throw new InvalidArgumentException($msg);
        }

        if (is_callable(self::$loadfunc)) {
            return call_user_func(self::$loadfunc, $yaml);
        }

        if (strpos($yaml, "\r") !== false) {
            $yaml = str_replace(["\r\n", "\r"], ["\n", "\n"], $yaml);
        }
        $lines = explode("\n", rtrim($yaml, "\n"));
        $loader = new Loader();

        foreach ($lines as $line) {
            $loader->parse($line);
        }

        return $loader->toArray();
    }

    /**
     * Load a file containing YAML and parse it into a PHP array.
     *
     * If the file cannot be opened, an exception is thrown.  If the
     * file is read but parsing fails, an empty array is returned.
     *
     * @param  string  $filename     Filename to load
     * @return array<string|int, mixed>  PHP array representation of YAML content
     * @throws InvalidArgumentException  If $filename is invalid
     * @throws Exception|RuntimeException If the file cannot be opened.
     */
    public static function loadFile(string $filename): array
    {
        if (!strlen($filename)) {
            $msg = 'Filename must be a string and cannot be empty';
            throw new InvalidArgumentException($msg);
        }

        $stream = @fopen($filename, 'rb');
        if (!$stream) {
            $lastError = error_get_last();
            if (class_exists('Horde_Exception')) {
                throw new Exception('Failed to open file: ', $lastError);
            }
            throw new RuntimeException('Failed to open file: ', $lastError['type'] ?? 0);
        }

        return self::loadStream($stream);
    }

    /**
     * Load YAML from a PHP stream resource.
     *
     * @param  resource  $stream     PHP stream resource
     * @return array<string|int, mixed>  PHP array representation of YAML content
     */
    public static function loadStream($stream): array
    {
        if (!is_resource($stream) || get_resource_type($stream) != 'stream') {
            throw new InvalidArgumentException('Stream must be a stream resource');
        }

        if (is_callable(self::$loadfunc)) {
            return call_user_func(self::$loadfunc, stream_get_contents($stream));
        }

        $loader = new Loader();
        while (!feof($stream)) {
            $line = stream_get_line($stream, 100000, "\n");
            if ($line !== false) {
                $loader->parse($line);
            }
        }

        return $loader->toArray();
    }

    /**
     * Dumps a PHP array to YAML.
     *
     * The dump method, when supplied with an array, will do its best to
     * convert the array into friendly YAML.
     *
     * @param  array<mixed>|Traversable $value  PHP array or Traversable object.
     * @param  array<string, mixed> $options  Options to pass to dumper.
     *
     * @return string  YAML representation of $value.
     */
    public static function dump(array|Traversable $value, array $options = []): string
    {
        if (is_callable(self::$dumpfunc)) {
            return call_user_func(self::$dumpfunc, $value);
        }

        $dumper = new Dumper();
        return $dumper->dump($value, $options);
    }
}
