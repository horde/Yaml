<?php

declare(strict_types=1);

/**
 * Round-trip with comment and style preservation.
 *
 *     php doc/examples/roundtrip.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;

$source = <<<YAML
    # Component manifest
    id: kronolith

    # Human-readable name shown in the UI.
    name: 'Kronolith'

    version: 5.9.0   # bump on every release
    YAML;

$stream = (new YamlStringLoader())->load($source);
$doc = $stream->getDocument(0);

$root = $doc->root();
assert($root instanceof MapNode);
$root->setEntry('version', '6.0.0');

echo (new YamlStringDumper())->dump($stream);
