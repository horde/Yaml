<?php

declare(strict_types=1);

/**
 * Splice a comment between two list items.
 *
 * Demonstrates positional comment manipulation: locate two existing
 * sequence items, insert a CommentNode between them, and emit the
 * result with the new comment in place. Every other byte unchanged.
 *
 *     php doc/examples/splice-comment.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;

$source = <<<YAML
    authors:
      - chuck@horde.org
      - jan@horde.org
      - mike@horde.org
    YAML;

$stream = (new YamlStringLoader())->load($source);
$root = $stream->getDocument(0)->root();
assert($root instanceof MapNode);

$authors = $root->entry('authors')?->getValue();
assert($authors instanceof SequenceNode);

// Splice a comment between item 1 (jan) and item 2 (mike).
//
// Choose the position by reference: insertCommentBefore() takes an
// index, a SequenceItem, a CommentNode, or a BlankLineNode. Here we
// pass the item index. The new CommentNode becomes a sibling of the
// items in the sequence's children list. The AST treats comments
// as first-class addressable nodes, not metadata.
$authors->insertCommentBefore(2, '# Joined the project in 2026.');

echo (new YamlStringDumper())->dump($stream);
