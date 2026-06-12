<?php

declare(strict_types=1);

/**
 * Byte-identical round-trip. Load and then dump produces the exact source.
 *
 * Demonstrates the document layer's USP: comments, blank lines,
 * end-of-line spacing, and quoting style all survive a round-trip
 * unchanged when the AST is not mutated.
 *
 *     php doc/examples/byte-identical-roundtrip.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;

$source = <<<YAML
    # Component manifest header
    # Maintained by hand. Do not auto-rewrite blindly.

    id: kronolith   # the component's stable id
    name: 'Kronolith'

    # Bump on every release.
    version: 6.0.0  # current target

    authors:
      - chuck@horde.org
      # primary maintainer
      - jan@horde.org

    YAML;

$stream = (new YamlStringLoader())->load($source);
$output = (new YamlStringDumper())->dump($stream);

echo "Source bytes: ", strlen($source), "\n";
echo "Output bytes: ", strlen($output), "\n";
echo "Identical:    ", ($source === $output ? 'yes' : 'NO'), "\n\n";

echo "----- emitted -----\n";
echo $output;
