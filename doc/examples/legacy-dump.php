<?php

declare(strict_types=1);

/**
 * Legacy Horde_Yaml::dump() example.
 *
 * Demonstrates the standalone legacy array dumper. New code should use
 * the document layer (see roundtrip.php and edit-nested.php).
 *
 *     php doc/examples/legacy-dump.php
 */

require __DIR__ . '/../../vendor/autoload.php';

$array[] = 'Sequence item';
$array['The Key'] = 'Mapped value';
$array[] = ['A sequence', 'of a sequence'];
$array[] = ['first' => 'A sequence', 'second' => 'of mapped values'];
$array['Mapped'] = ['A sequence', 'which is mapped'];
$array['A Note'] = 'What if your text is too long?';
$array['Another Note'] = 'If that is the case, the dumper will probably fold your text by using a block.  Kinda like this.';
$array['The trick?'] = 'The trick is that we overrode the default indent, 2, to 4 and the default wordwrap, 40, to 60.';
$array['Old Dog'] = "And if you want\n to preserve line breaks, \ngo ahead!";

echo "A PHP array run through Horde_Yaml::dump():\n";
var_dump(Horde_Yaml::dump($array, ['indent' => 4, 'wordwrap' => 60]));
