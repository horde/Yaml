<?php

use Horde\Test\Bootstrap;

$candidates = [
    dirname(__FILE__, 2) . '/vendor/autoload.php',
    dirname(__FILE__, 4) . '/autoload.php',
];
// Cover root case and library case
foreach ($candidates as $candidate) {
    if (file_exists($candidate)) {
        require_once $candidate;
        break;
    }
}
// Try to run without horde/test if it is not there.
if (class_exists(Bootstrap::class)) {
    Bootstrap::bootstrap(dirname(__FILE__));
}
