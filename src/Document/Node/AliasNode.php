<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document\Node;

use Horde\Yaml\Document\AnchorIndex;
use Horde\Yaml\Document\UnresolvedAliasException;
use InvalidArgumentException;
use RuntimeException;

/**
 * A YAML alias node. `*name` refers to a previously-anchored node.
 *
 * AliasNode is a leaf in the AST. It holds a target name (the string
 * after `*` in source) and a reference to the anchor index that
 * resolves it. Resolution is explicit via target(): the index is
 * consulted at call time, not at parse time. This keeps the AST
 * acyclic; aliases are not tree edges.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/03-ast-and-document-model-2026-06-11.md §2.7
 */
final class AliasNode implements Node
{
    use NodeTrait;

    private string $targetName;
    private ?AnchorIndex $anchorIndex;
    private ?CommentNode $eolComment = null;

    public function __construct(
        string $targetName = '',
        ?AnchorIndex $anchorIndex = null,
    ) {
        $this->targetName = $targetName;
        $this->anchorIndex = $anchorIndex;
    }

    public function getTargetName(): string
    {
        return $this->targetName;
    }

    public function setTargetName(string $name): void
    {
        $this->targetName = $name;
    }

    /**
     * Package-internal: bind this alias to an anchor index. Called by
     * the parser when constructing the alias.
     */
    public function setAnchorIndexInternal(?AnchorIndex $index): void
    {
        $this->anchorIndex = $index;
    }

    /**
     * Resolve this alias through its anchor index. Returns the
     * anchored node. Throws UnresolvedAliasException if the name is
     * not registered or the alias is not bound to an index.
     */
    public function target(): MapNode|SequenceNode|ScalarNode
    {
        if ($this->anchorIndex === null) {
            throw new UnresolvedAliasException(sprintf(
                'Alias *%s cannot be resolved: not bound to an anchor index',
                $this->targetName,
            ));
        }
        $node = $this->anchorIndex->lookup($this->targetName);
        if ($node === null) {
            throw new UnresolvedAliasException(sprintf(
                'Alias *%s does not refer to a known anchor',
                $this->targetName,
            ));
        }
        if ($node instanceof AliasNode) {
            throw new UnresolvedAliasException(sprintf(
                'Alias *%s targets another alias',
                $this->targetName,
            ));
        }
        return $node;
    }

    /**
     * Replace this alias node in the AST with a deep clone of the
     * anchored target. The clone has no anchor (so other aliases
     * still resolve to the original); the original target is
     * unchanged.
     *
     * Stage 2 case 6.2: detaching from a `<<:` merge context
     * preserves the merge form. The cloned map is inserted as the
     * new merge value.
     *
     * Returns the cloned node now sitting in this alias's former
     * position. The alias instance is detached from its parent and
     * should not be reused.
     */
    public function detach(): MapNode|SequenceNode|ScalarNode
    {
        $target = $this->target();
        $clone = $this->deepClone($target);
        $clone->setAnchor(null);

        $parent = $this->parent();
        if ($parent instanceof MapEntry) {
            $parent->setValueInternal($clone);
        } elseif ($parent instanceof SequenceItem) {
            $parent->setValueInternal($clone);
        } else {
            throw new RuntimeException(
                'AliasNode::detach() requires the alias to be a value of a MapEntry or SequenceItem',
            );
        }

        $this->setParent(null);
        return $clone;
    }

    /**
     * Deep-clone a value-position node. Returns a fresh subtree with
     * new node identity for every contained node. Anchors copy
     * verbatim (caller may need to clear them on the returned root
     * to avoid index conflicts).
     */
    private function deepClone(
        MapNode|SequenceNode|ScalarNode $node,
    ): MapNode|SequenceNode|ScalarNode {
        if ($node instanceof ScalarNode) {
            $copy = new ScalarNode(
                value: $node->getValue(),
                style: $node->getStyle(),
                rawSource: $node->getRawSource(),
                chomp: $node->getChomp(),
                indentIndicator: $node->getIndentIndicator(),
                anchor: $node->getAnchor(),
                tag: $node->getTag(),
            );
            return $copy;
        }
        if ($node instanceof MapNode) {
            $copy = new MapNode(
                style: $node->getStyle(),
                anchor: $node->getAnchor(),
                tag: $node->getTag(),
            );
            $copy->setFlowFormat($node->getFlowFormat());
            foreach ($node->children() as $child) {
                if ($child instanceof MapEntry) {
                    $clonedKey = $this->cloneAnyValue($child->getKey());
                    $clonedValue = $this->cloneAnyValue($child->getValue());
                    $clonedEntry = new MapEntry($clonedKey, $clonedValue);
                    $eol = $child->getEolComment();
                    if ($eol !== null) {
                        $clonedEntry->setEolComment(new CommentNode($eol->getText()));
                    }
                    $copy->appendChildInternal($clonedEntry);
                } elseif ($child instanceof CommentNode) {
                    $copy->appendChildInternal(new CommentNode($child->getText()));
                } elseif ($child instanceof BlankLineNode) {
                    $copy->appendChildInternal(new BlankLineNode($child->getCount()));
                }
            }
            return $copy;
        }
        // SequenceNode
        $copy = new SequenceNode(
            style: $node->getStyle(),
            anchor: $node->getAnchor(),
            tag: $node->getTag(),
        );
        $copy->setFlowFormat($node->getFlowFormat());
        foreach ($node->children() as $child) {
            if ($child instanceof SequenceItem) {
                $clonedValue = $this->cloneAnyValue($child->getValue());
                $clonedItem = new SequenceItem($clonedValue);
                $eol = $child->getEolComment();
                if ($eol !== null) {
                    $clonedItem->setEolComment(new CommentNode($eol->getText()));
                }
                $copy->appendChildInternal($clonedItem);
            } elseif ($child instanceof CommentNode) {
                $copy->appendChildInternal(new CommentNode($child->getText()));
            } elseif ($child instanceof BlankLineNode) {
                $copy->appendChildInternal(new BlankLineNode($child->getCount()));
            }
        }
        return $copy;
    }

    private function cloneAnyValue(
        MapNode|SequenceNode|ScalarNode|AliasNode $value,
    ): MapNode|SequenceNode|ScalarNode|AliasNode {
        if ($value instanceof AliasNode) {
            return new AliasNode($value->getTargetName(), $this->anchorIndex);
        }
        return $this->deepClone($value);
    }

    public function getEolComment(): ?CommentNode
    {
        return $this->eolComment;
    }

    public function setEolComment(CommentNode|string|null $comment): void
    {
        if ($comment === null) {
            if ($this->eolComment !== null) {
                $this->eolComment->setParent(null);
            }
            $this->eolComment = null;
            return;
        }
        if (is_string($comment)) {
            if (!str_starts_with($comment, '#')) {
                throw new InvalidArgumentException(
                    'Comment text must start with `#`; got: ' . $comment,
                );
            }
            $comment = new CommentNode($comment);
        }
        $this->eolComment = $comment;
        $comment->setParent($this);
    }
}
