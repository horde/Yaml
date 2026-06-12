<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$manifestPath = __DIR__ . '/../test/fixtures/conformance/yaml-test-suite-status.php';
$manifest = require $manifestPath;
$stale = [];
foreach ($manifest as $id => $status) {
    if (!str_starts_with($status, 'skip:loader threw')) {
        continue;
    }
    $path = __DIR__ . "/../test/fixtures/yaml-test-suite/$id/in.yaml";
    if (!file_exists($path)) {
        continue;
    }
    try {
        @(new Horde\Yaml\Document\YamlStringLoader())->load(file_get_contents($path));
        $stale[] = $id;
    } catch (Throwable) {
    }
}

$src = file_get_contents($manifestPath);
$skipPattern = "skip:loader threw ParseException (out-of-scope feature or parse limitation)";
foreach ($stale as $id) {
    $needle = "  '$id' => '" . $skipPattern . "',";
    $replace = "  '$id' => 'pass',";
    if (str_contains($src, $needle)) {
        $src = str_replace($needle, $replace, $src);
    }
}
file_put_contents($manifestPath, $src);
echo "Updated " . count($stale) . " entries: " . implode(', ', $stale) . "\n";
