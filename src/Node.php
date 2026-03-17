<?php

declare(strict_types=1);

/**
 * Horde YAML package
 *
 * This package is heavily inspired by the Spyc PHP YAML
 * implementation (http://spyc.sourceforge.net/), and portions are
 * copyright 2005-2006 Chris Wanstrath.
 *
 * @author   Chris Wanstrath <chris@ozmm.org>
 * @author   Chuck Hagenbuch <chuck@horde.org>
 * @author   Mike Naberezny <mike@maintainable.com>
 * @license  http://www.horde.org/licenses/bsd BSD
 * @category Horde
 * @package  Yaml
 */

namespace Horde\Yaml;

/**
 * A node, used for parsing YAML.
 *
 * Copyright 2005-2026 Chris Wanstrath <chris@ozmm.org>
 * Copyright 2006-2026 Alexey Zakhlestin <indeyets@gmail.com>
 * Copyright 2008-2026 Horde LLC (http://www.horde.org/)
 *
 * @category Horde
 * @package  Yaml
 */
class Node
{
    /**
     * Reference name for this node (if any)
     */
    public ?string $ref = null;

    /**
     * Reference key this node refers to (if any)
     */
    public ?string $refKey = null;

    /**
     * @param int|string $id Node identifier
     * @param int|string|null $parent Parent node ID
     * @param mixed $data Node data
     * @param int $indent Indentation level
     * @param bool $children Whether this node has children
     */
    public function __construct(
        public int|string $id,
        public int|string|null $parent = null,
        public mixed $data = null,
        public int $indent = 0,
        public bool $children = false
    ) {}
}
