<?php

declare(strict_types=1);

/**
 * Merge keys: share configuration between mappings.
 *
 *     php doc/examples/merge-keys.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;

$source = <<<YAML
    # Shared defaults
    defaults: &defaults
      driver: mysql
      port: 3306
      charset: utf8mb4

    production:
      <<: *defaults
      host: prod.example.com
      database: prod_db

    staging:
      <<: *defaults
      host: stage.example.com
      database: stage_db
      port: 3307           # explicit override of merged port
    YAML;

$stream = (new YamlStringLoader())->load($source);
$root = $stream->getDocument(0)->root();
assert($root instanceof MapNode);

// Resolved view: merged keys are flattened into the result.
foreach (['production', 'staging'] as $env) {
    $node = $root->entry($env)->getValue();
    assert($node instanceof MapNode);
    echo "[$env]\n";
    foreach ($node->resolved() as $k => $v) {
        printf("  %-10s = %s\n", $k, var_export($v, true));
    }
    echo "\n";
}

// Round-trip: the literal `<<: *defaults` source survives.
echo "--- round-trip output ---\n";
echo (new YamlStringDumper())->dump($stream);
