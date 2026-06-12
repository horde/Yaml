<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$manifestPath = __DIR__ . '/../test/fixtures/conformance/yaml-test-suite-status.php';
$manifest = require $manifestPath;

$buckets = [];
foreach ($manifest as $id => $status) {
    if (!str_starts_with($status, 'skip:loader threw')) {
        continue;
    }
    $path = __DIR__ . "/../test/fixtures/yaml-test-suite/$id/in.yaml";
    if (!file_exists($path)) {
        continue;
    }
    $src = file_get_contents($path);
    try {
        @(new Horde\Yaml\Document\YamlStringLoader())->load($src);
        continue;
    } catch (Throwable $e) {
        $msg = preg_replace('/at line \d+ column \d+.*$/', '', $e->getMessage());
        $msg = trim($msg);
    }

    // Heuristic: detect feature shape from input.
    $feature = 'other';
    if (preg_match('/^[\t]/m', $src)) {
        $feature = 'tab in indentation';
    } elseif (preg_match('/"\s*\n/', $src) || preg_match('/"[^"]*\n[^"]*"/', $src)) {
        $feature = 'multi-line double-quoted scalar';
    } elseif (preg_match("/'\\s*\\n/", $src) || preg_match("/'[^']*\\n[^']*'/", $src)) {
        $feature = 'multi-line single-quoted scalar';
    } elseif (preg_match('/^\s*\?\s*$/m', $src) || preg_match('/^\s*\?\s+\[/m', $src) || preg_match('/^\s*\?\s+\{/m', $src)) {
        $feature = 'explicit complex key (mapping/sequence)';
    } elseif (str_contains($src, "\r\n")) {
        $feature = 'CRLF line endings';
    } elseif (preg_match('/^[ \t]*#/m', $src) && str_contains($msg, "Unexpected content after document marker")) {
        $feature = 'comments around document markers';
    } elseif (str_contains($msg, 'Unexpected content after document marker')) {
        $feature = 'content immediately after document marker';
    } elseif (preg_match('/!<[^>]+>/', $src)) {
        $feature = 'verbatim tag form !<...>';
    } elseif (str_contains($msg, "Empty tag")) {
        $feature = 'empty tag !';
    } elseif (preg_match('/\?\s.*\n.*:\s/', $src) && str_contains($msg, "Unexpected character '?' in flow context")) {
        $feature = 'explicit key in flow';
    } elseif (preg_match('/^\s*-/', $src) && str_contains($msg, 'after `-`')) {
        $feature = 'sequence item edge case';
    } elseif (str_contains($msg, 'Empty document body')) {
        $feature = 'empty document body (multiple ---)';
    } elseif (preg_match('/^\.\.\.\s*\n.*\.\.\./ms', $src)) {
        $feature = 'document end marker ... in scalar context';
    } elseif (str_contains($msg, "Unexpected character ':'")) {
        $feature = "colon edge cases (':' alone, anchored)";
    } elseif (str_contains($msg, "Unexpected character 0x0A")) {
        $feature = 'newline-in-flow / tabs / mixed';
    } elseif (str_contains($msg, 'BlockEnd')) {
        $feature = 'block structure / dedent';
    } elseif (str_contains($msg, 'Expected scalar')) {
        $feature = 'flow / scalar position edge cases';
    }
    $buckets[$feature][] = [$id, $msg];
}

uasort($buckets, fn($a, $b) => count($b) - count($a));
$total = 0;
foreach ($buckets as $feature => $items) {
    $total += count($items);
}
echo "Total parse-exception skips: $total\n\n";
foreach ($buckets as $feature => $items) {
    printf("%4d  %s\n", count($items), $feature);
    foreach (array_slice($items, 0, 3) as [$id, $msg]) {
        printf("        %s : %s\n", $id, substr($msg, 0, 70));
    }
}
