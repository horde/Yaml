<?php

declare(strict_types=1);

/**
 * Walk a nested AST: read sequence items via the node API.
 *
 *     php doc/examples/edit-nested.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\YamlStringLoader;

$source = <<<YAML
    authors:
      - { name: Jan Schneider, email: jan@horde.org }
      - { name: Ralf Lang, email: lang@b1-systems.de }
    YAML;

$stream = (new YamlStringLoader())->load($source);
$root = $stream->getDocument(0)->root();
assert($root instanceof MapNode);

$authors = $root->entry('authors')?->getValue();
assert($authors instanceof SequenceNode);

foreach ($authors->items() as $i => $item) {
    $author = $item->getValue();
    assert($author instanceof MapNode);
    $name = $author->entry('name')?->getValue();
    $email = $author->entry('email')?->getValue();
    assert($name instanceof ScalarNode);
    assert($email instanceof ScalarNode);
    printf("[%d] %s <%s>\n", $i, $name->getValue(), $email->getValue());
}
