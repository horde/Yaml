<?php

declare(strict_types=1);

/**
 * Load a YAML source and read a few entries.
 *
 *     php doc/examples/load.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Horde\Yaml\Document\YamlStringLoader;

$source = <<<YAML
    # Component manifest
    id: kronolith
    name: Kronolith
    version: 6.0.0
    authors:
      - { name: Jan Schneider, email: jan@horde.org }
    YAML;

$stream = (new YamlStringLoader())->load($source);
$doc = $stream->getDocument(0);

echo 'id      = ', $doc->valueAt('id'), "\n";
echo 'name    = ', $doc->valueAt('name'), "\n";
echo 'version = ', $doc->valueAt('version'), "\n";
echo 'first author = ', $doc->valueAt('authors', 0, 'name'), "\n";
