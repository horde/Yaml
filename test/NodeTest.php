<?php
/**
 * Horde\Yaml\Node test
 *
 * @author  Mike Naberezny <mike@maintainable.com>
 * @license http://www.horde.org/licenses/bsd BSD
 * @category   Horde
 * @package    Yaml
 * @subpackage UnitTests
 */

namespace Horde\Yaml\Test;

use Horde_Yaml_Node;
use PHPUnit\Framework\TestCase;

/**
 * @category   Horde
 * @package    Yaml
 * @subpackage UnitTests
 */
class NodeTest extends TestCase
{
    public function testConstructorAssignsId()
    {
        $id = 'foo';
        $node = new Horde_Yaml_Node($id);
        $this->assertEquals($id, $node->id);
    }
}
