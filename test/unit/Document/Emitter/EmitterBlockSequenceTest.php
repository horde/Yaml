<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Emitter;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\Node\CommentNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\SequenceItem;
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\YamlDocument;
use Horde\Yaml\Document\YamlStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies D.03 emitter behavior: block sequences emit correctly.
 */
#[CoversClass(Emitter::class)]
final class EmitterBlockSequenceTest extends TestCase
{
    private function streamWithSequence(SequenceNode $seq): YamlStream
    {
        $stream = new YamlStream();
        $doc = new YamlDocument();
        $doc->setParentStream($stream);
        $doc->setRootInternal($seq);
        $stream->appendInternalDocument($doc);
        return $stream;
    }

    public function testSingleItemSequence(): void
    {
        $seq = new SequenceNode();
        $seq->appendChildInternal(new SequenceItem(new ScalarNode('foo')));
        $output = (new Emitter())->emit($this->streamWithSequence($seq));
        $this->assertSame("- foo\n", $output);
    }

    public function testMultipleItems(): void
    {
        $seq = new SequenceNode();
        $seq->appendChildInternal(new SequenceItem(new ScalarNode('a')));
        $seq->appendChildInternal(new SequenceItem(new ScalarNode('b')));
        $seq->appendChildInternal(new SequenceItem(new ScalarNode('c')));
        $output = (new Emitter())->emit($this->streamWithSequence($seq));
        $this->assertSame("- a\n- b\n- c\n", $output);
    }

    public function testTypedItemValues(): void
    {
        $seq = new SequenceNode();
        $seq->appendChildInternal(new SequenceItem(new ScalarNode(1)));
        $seq->appendChildInternal(new SequenceItem(new ScalarNode(true)));
        $seq->appendChildInternal(new SequenceItem(new ScalarNode(null)));
        $output = (new Emitter())->emit($this->streamWithSequence($seq));
        $this->assertSame("- 1\n- true\n- null\n", $output);
    }

    public function testItemWithEolComment(): void
    {
        $seq = new SequenceNode();
        $seq->appendChildInternal(
            new SequenceItem(new ScalarNode('foo'), new CommentNode('# important')),
        );
        $output = (new Emitter())->emit($this->streamWithSequence($seq));
        $this->assertSame("- foo  # important\n", $output);
    }

    public function testEmptyItemValue(): void
    {
        $seq = new SequenceNode();
        $seq->appendChildInternal(new SequenceItem());
        $output = (new Emitter())->emit($this->streamWithSequence($seq));
        $this->assertSame("-\n", $output);
    }

    public function testRetainsRawSourceOnItemValue(): void
    {
        $seq = new SequenceNode();
        $value = new ScalarNode(255, \Horde\Yaml\Document\Node\ScalarStyle::Plain, '0xFF');
        $seq->appendChildInternal(new SequenceItem($value));
        $output = (new Emitter())->emit($this->streamWithSequence($seq));
        $this->assertSame("- 0xFF\n", $output);
    }
}
