<?php

declare(strict_types=1);

/**
 * Legacy Horde_Yaml::loadFile() example.
 *
 * Demonstrates the standalone legacy array parser. New code should use
 * the document layer (see load.php). This example exists for
 * existing Horde components that still use the old API.
 *
 *     php doc/examples/legacy-load.php
 */

require __DIR__ . '/../../vendor/autoload.php';

$file = __DIR__ . '/legacy-example.yaml';
echo "$file loaded into PHP:\n";
var_dump(Horde_Yaml::loadFile($file));
