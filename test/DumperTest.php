<?php
/**
 * Horde\Yaml\Dumper test
 *
 * @author  Mike Naberezny <mike@maintainable.com>
 * @license http://www.horde.org/licenses/bsd BSD
 * @category   Horde
 * @package    Yaml
 * @subpackage UnitTests
 */

namespace Horde\Yaml\Test;

use ArrayObject;
use Horde\Yaml\Test\Helper\TestSerializable;
use Horde_Yaml;
use Horde_Yaml_Dumper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @category   Horde
 * @package    Yaml
 * @subpackage UnitTests
 */
class DumperTest extends TestCase
{
    protected Horde_Yaml_Dumper $dumper;
    public function setUp(): void
    {
        $this->dumper = new Horde_Yaml_Dumper();
    }

    // Pass-thru in Horde_Yaml

    public function testPassThruConvenienceFunction()
    {
        $value = [];

        // Disable any external dumper function
        $oldDumpFunc = Horde_Yaml::$dumpfunc;
        Horde_Yaml::$dumpfunc = false;

        $expected = $this->dumper->dump($value);
        $actual = Horde_Yaml::dump($value);

        // Restore the dumper function
        Horde_Yaml::$dumpfunc = $oldDumpFunc;

        $this->assertEquals($expected, $actual);
    }

    // Argument checking

    public function testThrowsWhenOptionsIsNotArray()
    {
        $options = 42;
        try {
            $this->dumper->dump([], $options);
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/options.*array/i', $e->getMessage());
        }
    }

    public function testThrowsWhenOptionIndentIsNotAnInteger()
    {
        $options = ['indent' => 'foo'];
        try {
            $this->dumper->dump([], $options);
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/indent.*integer/i', $e->getMessage());
        }
    }

    public function testThrowsWhenOptionWordwrapIsNotAnInteger()
    {
        $options = ['wordwrap' => 'foo'];
        try {
            $this->dumper->dump([], $options);
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/wordwrap.*integer/i', $e->getMessage());
        }
    }

    // Dumping

    public function testDumpAlwaysStartsNewYamlDocument()
    {
        $dump = $this->dumper->dump([]);
        $this->assertMatchesRegularExpression("/^---\n/", $dump);
    }

    public function testNumericStrings()
    {
        $value = ['foo' => '73,123'];
        $this->assertSame($value, Horde_Yaml::load(Horde_Yaml::dump($value)));
    }

    public function testEmtpyArray()
    {
        $value = ['foo' => []];

        $expected = "---\nfoo: []\n";
        $actual = $this->dumper->dump($value);
        $this->assertEquals($expected, $actual);
    }

    public function testDumpsArrayAsMap()
    {
        $value = ['foo' => 'bar'];

        $expected = "---\nfoo: bar\n";
        $actual = $this->dumper->dump($value);
        $this->assertEquals($expected, $actual);
    }

    public function testDumpsTraversableAsMap()
    {
        $value = new ArrayObject(['foo' => 'bar']);

        $expected = "---\nfoo: bar\n";
        $actual = $this->dumper->dump($value);
        $this->assertEquals($expected, $actual);
    }

    public function testMovedArray()
    {
        $arr = array_flip(range(1, 1000));
        $string = $this->dumper->dump($arr);
        $arr2 = Horde_Yaml::load($string);

        $this->assertEquals($arr, $arr2);
    }

    public function testMixedArray()
    {
        $arr = ['test', 'a' => 'test2', 'test3'];

        $string = $this->dumper->dump($arr);
        $arr2 = Horde_Yaml::load($string);

        $this->assertEquals($arr2, $arr);
    }

    public function testNegativeKeysArray()
    {
        $arr = [-1 => 'test', -2 => 'test2', 0 => 'test3'];

        $string = $this->dumper->dump($arr);
        $arr2 = Horde_Yaml::load($string);

        $this->assertEquals($arr, $arr2);
    }

    public function testInfinity()
    {
        $value = ['foo' => INF];

        $expected = "---\nfoo: .INF\n";
        $actual = $this->dumper->dump($value);
        $this->assertEquals($expected, $actual);
    }

    public function testNegativeInfinity()
    {
        $value = ['foo' => -INF];

        $expected = "---\nfoo: -.INF\n";
        $actual = $this->dumper->dump($value);
        $this->assertEquals($expected, $actual);
    }

    public function testNan()
    {
        $value = ['foo' => NAN];

        $expected = "---\nfoo: .NAN\n";
        $actual = $this->dumper->dump($value);
        $this->assertEquals($expected, $actual);
    }

    public function testSerializable()
    {
        $value = ['obj' => new TestSerializable('s')];

        $expected = "---\nobj: >-\n  !php/object::Horde\Yaml\Test\Helper\TestSerializable\n  s\n";
        $actual = $this->dumper->dump($value);
        $this->assertEquals($expected, $actual);
    }

    public function testDumpSequence()
    {
        $value = ['foo', 'bar'];

        $expected = "---\n"
                  . "- foo\n"
                  . "- bar\n";
        $actual = $this->dumper->dump($value);

        $this->assertEquals($expected, $actual);
    }

    public function testStringLiteral()
    {
        $value = ['string' => "foo\nbar\n"];
        $expected = "---\nstring: |\n  foo\n  bar\n";
        $string = $this->dumper->dump($value);
        $this->assertEquals($expected, $string);
        $this->assertEquals($value, Horde_Yaml::load($string));

        $value = ['string' => "foo\nbar"];
        $expected = "---\nstring: |-\n  foo\n  bar\n";
        $string = $this->dumper->dump($value);
        $this->assertEquals($expected, $string);
        $this->assertEquals($value, Horde_Yaml::load($string));

        $value = ['string' => "foo\nbar\n\n"];
        $expected = "---\nstring: |+\n  foo\n  bar\n  \n";
        $string = $this->dumper->dump($value);
        $this->assertEquals($expected, $string);
        $this->assertEquals($value, Horde_Yaml::load($string));
    }

    public function testShouldWrapStringsWithCommentDelimiterInQuotes()
    {
        $value = ['foo' => 'string # this is not a comment'];
        $expected = "---\n"
                    . "foo: '{$value['foo']}'\n";
        $actual = $this->dumper->dump($value);
        $this->assertEquals($expected, $actual);
        // round-trip assert
        $this->assertEquals($value, Horde_Yaml::load($actual), "Error with: >{$actual}<");
    }

    public function testShouldNotWrapStringsWithCommentDelimiterForFoldedStrings()
    {
        // stringWithHash: 'string # this is part of the string, not a comment'
        $value = ['foo' => 'string # this is not a comment but it is a long string that gets folded', 'bar' => 2];
        $expected = "---\n"
                    . "foo: >-\n"
                    . "  string # this is not a comment but it is\n"
                    . "  a long string that gets folded\n"
                    . "bar: 2\n";

        $actual = $this->dumper->dump($value);
        $this->assertEquals($expected, $actual);
        // round-trip assert
        // parsing keeps trailing newlines.
        $this->assertEquals($value, Horde_Yaml::load($actual));
    }
}
