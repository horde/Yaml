<?php

declare(strict_types=1);

/**
 * Horde\Yaml\Loader test
 *
 * Copyright 2008-2026 Horde LLC (http://www.horde.org/)
 *
 * @author     Mike Naberezny <mike@maintainable.com>
 * @license    http://www.horde.org/licenses/bsd BSD
 * @category   Horde
 * @package    Yaml
 * @subpackage UnitTests
 */

namespace Horde\Yaml\Test\Unit;

use ArrayObject;
use DomainException;
use Horde\Yaml\Exception;
use Horde\Yaml\Loader;
use Horde\Yaml\Test\Helper\LoaderTestMockLoader;
use Horde\Yaml\Test\Helper\TestNotSerializable;
use Horde\Yaml\Test\Helper\TestSerializable;
use Horde\Yaml\Yaml;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XMLParser;

/**
 * @category   Horde
 * @package    Yaml
 * @subpackage UnitTests
 */
#[CoversClass(Yaml::class)]
#[CoversClass(Loader::class)]
class LoaderTest extends TestCase
{
    public function setUp(): void
    {
        Yaml::$loadfunc = 'nonexistant_callback';
    }

    // Loading: load()

    public function testLoad(): void
    {
        $expected = ['foo' => 'bar'];
        $actual = Yaml::load('foo: bar');

        $this->assertEquals($expected, $actual);
    }

    public function testLoadUsesCallbackForParsingIfAvailable(): void
    {
        Yaml::$loadfunc = '\Horde\Yaml\Test\Helper\LoaderTestMockLoader::returnArray';

        $yaml = 'foo';
        $expected = LoaderTestMockLoader::returnArray($yaml);
        $actual   = Yaml::load($yaml);

        $this->assertEquals($expected, $actual);
    }

    public function testLoadThrowsWhenInputStringIsEmpty(): void
    {
        $emptyString = '';
        try {
            Yaml::load($emptyString);
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/cannot be empty/i', $e->getMessage());
        }
    }

    public function testLoadReturnsEmptyArrayWhenStringCannotBeParsedAsYaml(): void
    {
        $notYaml = 'notyaml';
        $this->assertEquals([], Yaml::load($notYaml));
    }

    // Loading: loadFile()

    public function testLoadFile(): void
    {
        $parsed = Yaml::loadFile($this->fixture('basic'));
        $this->assertEquals('bar', $parsed['foo']);
    }

    public function testLoadFileThrowsWhenFilenameIsEmptyString(): void
    {
        $emptyString = '';
        try {
            Yaml::loadFile($emptyString);
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/cannot be empty/i', $e->getMessage());
        }
    }

    public function testLoadFileThrowsWhenFilenameCannotBeOpened(): void
    {
        $nonexistant = '/path/to/a/nonexistant/filename';
        try {
            Yaml::loadFile($nonexistant);
            $this->fail();
        } catch (Exception|RuntimeException $e) {
            $this->assertMatchesRegularExpression('/failed to open/i', $e->getMessage());
        }
    }

    // Loading: loadStream()

    public function testLoadStream(): void
    {
        $fp = fopen($this->fixture('basic'), 'rb');
        $parsed = Yaml::loadStream($fp);
        $this->assertEquals('bar', $parsed['foo']);
    }

    public function testLoadStreamThrowsWhenStreamIsNotResource(): void
    {
        $notResource = 42;
        try {
            Yaml::loadStream($notResource);
        } catch (InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/stream resource/i', $e->getMessage());
        }
    }

    /**
     * Correctly error on non-stream resource
     *
     * This test becomes less useful
     * as there are few resources left in PHP 8.x
     */
    public function testLoadStreamThrowsWhenStreamIsResourceButNotStream(): void
    {
        $resourceButNotStream = xml_parser_create();
        if (PHP_VERSION_ID > 80000) {
            $this->assertInstanceOf(XMLParser::class, $resourceButNotStream);
        } else {
            $this->assertIsResource($resourceButNotStream);
        }

        try {
            Yaml::loadStream($resourceButNotStream);
        } catch (InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/stream resource/i', $e->getMessage());
        }
    }

    public function testLoadStreamUsesCallbackForParsingIfAvailable(): void
    {
        Yaml::$loadfunc = 'Horde\Yaml\Test\Helper\LoaderTestMockLoader::returnArray';

        $stream = fopen('php://memory', 'r');
        $expected = LoaderTestMockLoader::returnArray($stream);
        $actual   = Yaml::loadStream($stream);

        $this->assertEquals($expected, $actual);
    }

    // Parsing: Mappings

    public function testMappingStringValue(): void
    {
        $yaml = "String: Anyone's name, really.";
        $parsed = Yaml::load($yaml);
        $this->assertEquals("Anyone's name, really.", $parsed['String']);
    }

    public function testMappingIntegerValue(): void
    {
        $yaml = 'Int: 13';
        $parsed = Yaml::load($yaml);
        $this->assertEquals(13, $parsed['Int']);
    }

    public function testMappingIntegerZeroValue(): void
    {
        $yaml = 'Zero: 0';
        $parsed = Yaml::load($yaml);
        $this->assertSame(0, $parsed['Zero']);
    }

    public function testMappingFloatValue(): void
    {
        $yaml = 'Float: 5.34';
        $parsed = Yaml::load($yaml);
        $this->assertEquals(5.34, $parsed['Float']);
    }

    public function testMappingBooleanTrue(): void
    {
        $trues = ['TRUE', 'True', 'true', 'On', 'on', '+', 'YES', 'Yes', 'yes'];
        foreach ($trues as $true) {
            $yaml = "True: $true";
            $parsed = Yaml::load($yaml);
            $this->assertTrue($parsed['True'], $true);
        }
    }

    public function testMappingBooleanFalse(): void
    {
        $falses = ['FALSE', 'False', 'false', 'Off', 'off', '-', 'NO', 'No', 'no'];
        foreach ($falses as $false) {
            $yaml = "False: $false";
            $parsed = Yaml::load($yaml);
            $this->assertFalse($parsed['False'], $false);
        }
    }

    public function testMappingNullValue(): void
    {
        $nulls = ['NULL', 'Null', 'null', '', '~'];
        foreach ($nulls as $null) {
            $yaml = "Null: $null";
            $parsed = Yaml::load($yaml);
            $this->assertNull($parsed['Null'], $null);
        }
    }

    public function testMappedValueWithFoldedBlock(): void
    {
        $parsed = Yaml::loadFile($this->fixture('basic'));

        $expected = "There isn't any time for your tricks!\nDo you understand?\n";
        $actual = $parsed['no time'];
        $this->assertEquals($expected, $actual);
    }

    public function testMappedValueWithMapping(): void
    {
        $yaml = "foo:\n"
              . "  bar: baz";
        $expected = ['foo' => ['bar' => 'baz']];
        $actual   = Yaml::load($yaml);
        $this->assertEquals($expected, $actual);
    }

    // Parsing: Types

    public function testFloatExponential(): void
    {
        $this->assertSame(['e' => 10.0], Yaml::load('e: 1.0e+1'));
        $this->assertSame(['e' => 0.1], Yaml::load('e: 1.0e-1'));
    }

    public function testInfinity(): void
    {
        $this->assertSame(['i' => INF], Yaml::load('i: .inf'));
        $this->assertSame(['i' => INF], Yaml::load('i: .Inf'));
        $this->assertSame(['i' => INF], Yaml::load('i: .INF'));
    }

    public function testNegativeInfinity(): void
    {
        $this->assertSame(['i' => -INF], Yaml::load('i: -.inf'));
        $this->assertSame(['i' => -INF], Yaml::load('i: -.Inf'));
        $this->assertSame(['i' => -INF], Yaml::load('i: -.INF'));
    }

    public function testNan(): void
    {
        // NAN !== NAN, but NAN == NAN
        $yaml = Yaml::load('n: .nan');
        $this->assertTrue(is_nan($yaml['n']));
        $yaml = Yaml::load('n: .NaN');
        $this->assertTrue(is_nan($yaml['n']));
        $yaml = Yaml::load('n: .NAN');
        $this->assertTrue(is_nan($yaml['n']));
    }

    public function testArray(): void
    {
        $this->assertEquals(['a' => []], Yaml::load('a: []'));
        $this->assertEquals(['a' => ['a', 'b', 'c']], Yaml::load('a: [a, b, c]'));
        $this->assertEquals(['a' => []], Yaml::load('a: !php/array []'));

        // ArrayObject implements ArrayAccess: OK
        $this->assertEquals(['ao' => new ArrayObject()], Yaml::load('ao: !php/array::ArrayObject []'));
        $this->assertEquals(['ao' => new ArrayObject([1, 2, 3])], Yaml::load('ao: !php/array::ArrayObject [1, 2, 3]'));

        // TestNotSerializable doesn't implement ArrayAccess: FAILURE
        Yaml::$allowedClasses[] = TestNotSerializable::class;
        try {
            Yaml::load('array: !php/array::Horde\Yaml\Test\Helper\TestNotSerializable []');
            $this->fail();
        } catch (Exception|LogicException $e) {
            $this->assertEquals('Horde\Yaml\Test\Helper\TestNotSerializable does not implement ArrayAccess', $e->getMessage());
        }

        // OtherClass doesn't exist: FAILURE
        Yaml::$allowedClasses[] = 'Horde_Yaml_Test_OtherClass';
        try {
            Yaml::load('array: !php/array::Horde_Yaml_Test_OtherClass []');
            $this->fail();
        } catch (Exception|LogicException $e) {
            $this->assertEquals('Horde_Yaml_Test_OtherClass is not defined', $e->getMessage());
        }

        // Disallowed is not whitelisted
        try {
            Yaml::load('array: !php/array::Horde_Yaml_Test_Disallowed []');
            $this->fail();
        } catch (Exception|LogicException $e) {
            $this->assertEquals('Horde_Yaml_Test_Disallowed is not in the list of allowed classes', $e->getMessage());
        }
    }

    public function testHash(): void
    {
        $this->assertEquals(['a' => []], Yaml::load('a: {}'));
        $this->assertEquals(['a' => ['a', 'b', 'c']], Yaml::load('a: {0: a, 1: b, 2: c}'));

        // ArrayObject implements ArrayAccess: OK
        $this->assertEquals(['ao' => new ArrayObject()], Yaml::load('ao: !php/hash::ArrayObject {}'));
        $this->assertEquals(
            ['ao' => new ArrayObject(['a' => 1, 'b' => 2, 3 => 3, 4 => 'd', 'e' => 5])],
            Yaml::load('ao: !php/hash::ArrayObject {a: 1, b: 2, 3: 3, 4: d, e: 5}')
        );

        // TestNotSerializable doesn't implement ArrayAccess: FAILURE
        Yaml::$allowedClasses[] = TestNotSerializable::class;
        try {
            Yaml::load('hash: !php/hash::Horde\Yaml\Test\Helper\TestNotSerializable {}');
            $this->fail();
        } catch (Exception|LogicException $e) {
            $this->assertEquals('Horde\Yaml\Test\Helper\TestNotSerializable does not implement ArrayAccess', $e->getMessage());
        }

        // OtherClass doesn't exist: FAILURE
        Yaml::$allowedClasses[] = 'Horde_Yaml_Test_OtherClass';
        try {
            Yaml::load('hash: !php/hash::Horde_Yaml_Test_OtherClass {}');
            $this->fail();
        } catch (Exception|LogicException $e) {
            $this->assertEquals('Horde_Yaml_Test_OtherClass is not defined', $e->getMessage());
        }

        // Disallowed is not whitelisted
        try {
            Yaml::load('hash: !php/hash::Horde_Yaml_Test_Disallowed []');
            $this->fail();
        } catch (Exception|LogicException $e) {
            $this->assertEquals('Horde_Yaml_Test_Disallowed is not in the list of allowed classes', $e->getMessage());
        }
    }

    public function testSerializable(): void
    {
        Yaml::$allowedClasses[] = TestSerializable::class;
        $result = Yaml::load('obj: >
  !php/object::Horde\Yaml\Test\Helper\TestSerializable
  string');

        $this->assertInstanceOf(TestSerializable::class, $result['obj']);
        $this->assertSame('string', $result['obj']->test());

        // TestNotSerializable doesn't implement Serializable: FAILURE
        Yaml::$allowedClasses[] = TestNotSerializable::class;
        try {
            Yaml::load('o: !php/object::Horde\Yaml\Test\Helper\TestNotSerializable string');
            $this->fail();
        } catch (Exception|LogicException $e) {
            $this->assertEquals('Horde\Yaml\Test\Helper\TestNotSerializable does not implement Serializable', $e->getMessage());
        }

        // Disallowed is not whitelisted
        try {
            Yaml::load('o: !php/object::Horde_Yaml_Test_Disallowed string');
            $this->fail();
        } catch (Exception|LogicException $e) {
            $this->assertEquals('Horde_Yaml_Test_Disallowed is not in the list of allowed classes', $e->getMessage());
        }
    }

    // Parsing: Sequences

    public function testSequenceBasic(): void
    {
        $yaml = "- PHP Class\n"
              . "- Basic YAML Loader\n"
              . "- Very Basic YAML Dumper";
        $parsed = Yaml::load($yaml);

        $this->assertEquals("PHP Class", $parsed[0]);
        $this->assertEquals("Basic YAML Loader", $parsed[1]);
        $this->assertEquals("Very Basic YAML Dumper", $parsed[2]);
    }

    public function testSequenceOfSequence(): void
    {
        $yaml = "-\n"
              . "  - YAML is so easy to learn.\n"
              . "  - Your config files will never be the same.";
        $parsed = Yaml::load($yaml);

        $expected = ["YAML is so easy to learn.",
            "Your config files will never be the same.", ];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testSequenceofMappings(): void
    {
        $yaml = "-\n"
              . "  cpu: 1.5ghz\n"
              . "  ram: 1 gig\n"
              . "  os : os x 10.4.1";
        $parsed = Yaml::load($yaml);

        $expected = ["cpu" => "1.5ghz", "ram" => "1 gig", "os" => "os x 10.4.1"];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual, 'Sequence of mappings');
    }

    public function testMappedSequence(): void
    {
        $yaml = "domains:\n"
              . "  - yaml.org\n"
              . "  - php.net\n";
        $parsed = Yaml::load($yaml);

        $expected = ["yaml.org", "php.net"];
        $actual = $parsed['domains'];
        $this->assertEquals($expected, $actual);
    }

    public function testSequenceWithMappedValuesStartingWithCaps(): void
    {
        $yaml = "- program: Adium\n"
              . "  platform: OS X\n"
              . "  type: Chat Client\n";
        $parsed = Yaml::load($yaml);

        $expected = ["program" => "Adium", "platform" => "OS X", "type" => "Chat Client"];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    // Parsing: References

    public function testReferencesAssignment1(): void
    {
        $parsed = Yaml::loadFile($this->fixture('references'));

        $expected = ['Perl', 'Python', 'PHP', 'Ruby'];
        $actual = $parsed['dynamic languages'];
        $this->assertEquals($expected, $actual);
    }

    public function testReferencesAssignment2(): void
    {
        $parsed = Yaml::loadFile($this->fixture('references'));

        $expected = ['C/C++', 'Java'];
        $actual = $parsed['compiled languages'];
        $this->assertEquals($expected, $actual);
    }

    public function testReferenceUsage(): void
    {
        $parsed = Yaml::loadFile($this->fixture('references'));

        $assignment1 = ['Perl', 'Python', 'PHP', 'Ruby'];
        $assignment2 = ['C/C++', 'Java'];

        $expected = [$assignment1, $assignment2];
        $actual = $parsed['all languages'];

        $this->assertEquals($expected, $actual);
    }

    // Parsing: Inlines

    public function testInlinedSequence(): void
    {
        $yaml = '- [One, Two, Three, Four]';
        $parsed = Yaml::load($yaml);

        $expected = ["One", "Two", "Three", "Four"];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineSequenceWithQuotes(): void
    {
        $yaml = "- ['complex: string', 'another [string]']";
        $parsed = Yaml::load($yaml);

        $expected = ['complex: string', 'another [string]'];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineSequenceOneDeep(): void
    {
        $yaml = '- [One, [Two, And, Three], Four, Five]';
        $parsed = Yaml::load($yaml);

        $expected = ["One", ["Two", "And", "Three"], "Four", "Five"];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineSequenceOneDeepWithQuotes(): void
    {
        $yaml = '- [a, [\'1\', "2"], b]';
        $parsed = Yaml::load($yaml);

        $expected = ['a', ['1', '2'], 'b'];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineSequenceTwoDeep(): void
    {
        $yaml = '- [This, [Is, Getting, [Ridiculous, Guys]], Seriously, [Show, Mercy]]';
        $parsed = Yaml::load($yaml);

        $expected = ["This", ["Is", "Getting", ["Ridiculous", "Guys"]],
            "Seriously", ["Show", "Mercy"], ];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineSequenceWhenEmpty(): void
    {
        $yaml = '- []';
        $parsed = Yaml::load($yaml);

        $expected = [];
        $actual = $parsed[0];
        $this->assertEquals($actual, $expected);
    }

    public function testInlineSequenceWhenEmptyWithWhitespace(): void
    {
        $yaml = "- [ \t]";
        $parsed = Yaml::load($yaml);

        $expected = [];
        $actual = $parsed[0];
        $this->assertEquals($actual, $expected);
    }

    public function testInlineMapping(): void
    {
        $yaml = '- {name: chris, age: young, brand: lucky strike}';
        $parsed = Yaml::load($yaml);

        $expected = ["name" => "chris", "age" => "young", "brand" => "lucky strike"];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineMappingWhenEmpty(): void
    {
        $yaml = '- {}';
        $parsed = Yaml::load($yaml);

        $expected = [];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineMappingWhenEmptyWithWhitespace(): void
    {
        $yaml = "- { \t}";
        $parsed = Yaml::load($yaml);

        $expected = [];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineMappingWithQuotesInlinedInSequence(): void
    {
        $yaml = '- {name: "Foo, Bar\'s", age: 20}';
        $parsed = Yaml::load($yaml);

        $expected = ['name' => "Foo, Bar's", 'age' => 20];
        $actual = $parsed[0];

        $this->assertEquals($expected, $actual);
    }


    public function testInlineMappingWithQuotesInlinedInMapping(): void
    {
        $yaml = 'outer: { inner1: "foo bar", inner2: \'baz qux\' }';

        $expected = ['outer' => ['inner1' => "foo bar", 'inner2' => "baz qux"]];
        $actual = Yaml::load($yaml);
        $this->assertEquals($expected, $actual);
    }

    public function testInlineMappingOneDeep(): void
    {
        $yaml = "- {name: mark, age: older than chris, brand: [marlboro, lucky strike]}";
        $parsed = Yaml::load($yaml);

        $expected = ["name" => "mark", "age" => "older than chris",
            "brand" => ["marlboro", "lucky strike"], ];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    // Parsing: Quotes

    public function testQuotesCanBeEscaped(): void
    {
        $yaml = "- one'apostrophe on line\n"
              . "- two'apostrophes' on line\n"
              . "- one\"quote on line\n"
              . "- two\"quotes\" on line\n";
        $parsed = Yaml::load($yaml);

        $this->assertEquals(
            "one'apostrophe on line",
            $parsed[0]
        );

        $this->assertEquals(
            "two'apostrophes' on line",
            $parsed[1]
        );

        $this->assertEquals(
            'one"quote on line',
            $parsed[2]
        );

        $this->assertEquals(
            'two"quotes" on line',
            $parsed[3]
        );
    }

    public function testQuotesCanBeUsedForComplexKeys(): void
    {
        $yaml = '"if: you\'d": like';
        $parsed = Yaml::load($yaml);

        $this->assertEquals("like", $parsed["if: you'd"]);
    }

    public function testQuotesCanBeEmptyWhenQuotes(): void
    {
        $yaml = 'empty: ""';
        $parsed = Yaml::load($yaml);

        $this->assertSame('', $parsed['empty']);
    }

    public function testQuotesCanBeEmptyWhenApostrophes(): void
    {
        $yaml = "empty: ''";
        $parsed = Yaml::load($yaml);

        $this->assertSame('', $parsed['empty']);
    }

    public function testWhitespaceBetweenQuotesIsPreserved(): void
    {
        $yaml = 'empty: "   "';
        $parsed = Yaml::load($yaml);

        $this->assertSame('   ', $parsed['empty']);
    }

    public function testWhitespaceBetweenApostrophesIsPreserved(): void
    {
        $yaml = "empty: '   '";
        $parsed = Yaml::load($yaml);

        $this->assertSame('   ', $parsed['empty']);
    }

    // Parsing: Keys

    public function testKeyAsNumeric(): void
    {
        // Added in Spyc .2
        $yaml = '1040: Ooo, a numeric key! # And working comments? Wow!';
        $parsed = Yaml::load($yaml);

        $this->assertEquals("Ooo, a numeric key!", $parsed[1040]);
    }

    // Tab Detection

    public function testThrowsAnExceptionWhenFirstCharacterOfLineIsTab(): void
    {
        try {
            Yaml::load("\tfoo: bar");
            $this->fail();
        } catch (Exception $e) {
            $this->assertMatchesRegularExpression('/indent contains a tab/i', $e->getMessage());
        } catch (DomainException $e) {
            $this->assertMatchesRegularExpression('/indent contains a tab/i', $e->getMessage());
        }
    }

    public function testThrowsExceptionWhenLineIndentContainsTab(): void
    {
        try {
            Yaml::load(" \tfoo: bar");
            $this->fail();
        } catch (Exception|DomainException $e) {
            $this->assertMatchesRegularExpression('/indent contains a tab/i', $e->getMessage());
        }
    }

    public function testDoesNotThrowOnAnEmptyLineWithTabsOrSpaces(): void
    {
        /**
         * Workaround: There is no specific method to show no exception is
         * raised. Just running code without any asserts will get the unit
         * test marked "risky" by phpunit > 6
         */
        $exception = null;
        try {
            Yaml::load(" ");
            Yaml::load("\t");
            Yaml::load(" \t");
            Yaml::load("\t ");
        } catch (\Exception $e) {
            $exception = $e;
        }
        $this->assertNull($exception);
    }

    // Comments

    public function testCommentOnEmptyLine(): void
    {
        $yaml = "# foo\nbar: baz";
        $expected = ['bar' => 'baz'];
        $actual = Yaml::load($yaml);
        $this->assertEquals($expected, $actual);
    }

    public function testCommentAtEndOfLine(): void
    {
        $yaml = 'foo: bar # baz';
        $parsed = Yaml::load($yaml);

        $expected = 'bar';
        $actual = $parsed['foo'];
        $this->assertEquals($expected, $actual);
    }

    public function testDecoyCommentEmbeddedInQuotes(): void
    {
        $yaml = 'foo: "bar # baz"';
        $parsed = Yaml::load($yaml);

        $expected = 'bar # baz';
        $actual = $parsed['foo'];
        $this->assertEquals($expected, $actual);
    }

    public function testDecoyCommentEmbeddedInQuotesAndEndOfLineComment(): void
    {
        $yaml = 'foo: "bar # baz" # qux';
        $parsed = Yaml::load($yaml);

        $expected = 'bar # baz';
        $actual = $parsed['foo'];
        $this->assertEquals($expected, $actual);
    }

    public function testDecoyCommentEmbeddedInApostrophes(): void
    {
        $yaml = "foo: 'bar # baz'";
        $parsed = Yaml::load($yaml);

        $expected = 'bar # baz';
        $actual = $parsed['foo'];
        $this->assertEquals($expected, $actual);
    }

    public function testDecoyCommentEmbeddedInApostrophesAndEndOfLineVersion(): void
    {
        $yaml = "foo: 'bar # baz' # qux";
        $parsed = Yaml::load($yaml);

        $expected = 'bar # baz';
        $actual = $parsed['foo'];
        $this->assertEquals($expected, $actual);
    }

    // Chomping

    public function testChompClip(): void
    {
        $parsed = Yaml::loadFile($this->fixture('basic'));
        $this->assertEquals("Line 1\nLine 2\n", $parsed['chompClip']);
    }

    public function testChompStrip(): void
    {
        $parsed = Yaml::loadFile($this->fixture('basic'));
        $this->assertEquals("Line 1\nLine 2", $parsed['chompStrip']);
    }

    public function testChompKeep(): void
    {
        $parsed = Yaml::loadFile($this->fixture('basic'));
        $this->assertEquals("Line 1\nLine 2\n\n\n", $parsed['chompKeep']);
    }

    // Misc

    public function testComplexParse(): void
    {
        $yaml = "databases:\n"
              . "  - name: spartan\n"
              . "    notes:\n"
              . "      - Needs to be backed up\n"
              . "      - Needs to be normalized\n"
              . "    type: mysql\n";
        $expected = ['databases' => [['name' => 'spartan',
            'notes' => ['Needs to be backed up',
                'Needs to be normalized', ],
            'type' => 'mysql', ]]];
        $actual = Yaml::load($yaml);
        $this->assertEquals($expected, $actual);

        $yaml = <<<YAML
            authors:
              -
                name: Gunnar Wrobel
                user: wrobel
                email: p@rdus.de
                active: true
                role: lead
            dependencies:
              required:
                php: ^5
                pear:
                  pear.php.net/Console_Getopt: '*'
              optional:
                pear:
                  pecl.php.net/PECL: '*'
            YAML;
        $expected = [
            'authors' => [
                [
                    'name' => 'Gunnar Wrobel',
                    'user' => 'wrobel',
                    'email' => 'p@rdus.de',
                    'active' => true,
                    'role' => 'lead',
                ],
            ],
            'dependencies' => [
                'required' => [
                    'php' => '^5',
                    'pear' => [
                        'pear.php.net/Console_Getopt' => '*',
                    ],
                ],
                'optional' => [
                    'pear' => [
                        'pecl.php.net/PECL' => '*',
                    ],
                ],
            ],
        ];
        $actual = Yaml::load($yaml);
        $this->assertEquals($expected, $actual);
    }

    public function testUnliteralizing(): void
    {
        $parsed = Yaml::loadFile($this->fixture('basic'));
        $expected = "Line #1\nLine #2\n";
        $this->assertEquals($expected, $parsed['literalStringTest']);
    }

    public function testUnfolding(): void
    {
        $parsed = Yaml::loadFile($this->fixture('basic'));
        $expected = "Line 1 Line 2\n";
        $this->assertEquals($expected, $parsed['foldedStringTest']);
        $expected = "The Horde Application Framework is a flexible, modular, general-purpose web application framework written in PHP. It provides an extensive array of components that are targeted at the common problems and tasks involved in developing modern web applications.\n";
        $this->assertEquals($expected, $parsed['description']);
    }

    // Test Helpers

    public function fixture(string $name): string
    {
        return __DIR__ . "/../fixtures/{$name}.yml";
    }

    // =========================================================================
    // GROUP 1: Root Cause - Line 410 (_parseLine)
    // These tests verify the fix at the source where keys first enter the system.
    // =========================================================================

    /**
     * Test that empty string keys are preserved in simple key-value pairs.
     *
     * Root cause test: Line 410 in _parseLine() should NOT use empty($key)
     * which incorrectly treats "" as missing key.
     */
    public function testEmptyStringKeySimplePair(): void
    {
        $yaml = <<<YAML
            "": value
            YAML;

        $result = Yaml::load($yaml);

        // Assert empty string key exists
        $this->assertArrayHasKey('', $result);
        $this->assertEquals('value', $result['']);

        // Assert it wasn't converted to numeric 0
        $this->assertArrayNotHasKey(0, $result);

        // Verify array has exactly 1 element
        $this->assertCount(1, $result);
    }

    /**
     * Test empty string key coexisting with normal keys.
     *
     * Verifies line 410 doesn't affect other keys in same associative array.
     */
    public function testEmptyStringKeyWithOtherKeys(): void
    {
        $yaml = <<<YAML
            "": empty-key-value
            normalKey: normal-value
            anotherKey: another-value
            YAML;

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey('', $result);
        $this->assertEquals('empty-key-value', $result['']);
        $this->assertArrayHasKey('normalKey', $result);
        $this->assertEquals('normal-value', $result['normalKey']);
        $this->assertArrayHasKey('anotherKey', $result);
        $this->assertEquals('another-value', $result['anotherKey']);
        $this->assertCount(3, $result);
    }

    /**
     * Test empty string key in nested YAML structures.
     *
     * Real-world case: PSR-0 autoload with empty prefix.
     */
    public function testEmptyStringKeyNested(): void
    {
        $yaml = <<<YAML
            autoload:
              psr-0:
                "": compat/
                Horde_Exception: lib/
              psr-4:
                Horde\\Exception\\: src/
            YAML;

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey('autoload', $result);
        $this->assertArrayHasKey('psr-0', $result['autoload']);

        // Critical assertion: empty string key preserved
        $this->assertArrayHasKey('', $result['autoload']['psr-0']);
        $this->assertEquals('compat/', $result['autoload']['psr-0']['']);

        // Critical assertion: NOT converted to 0
        $this->assertArrayNotHasKey(0, $result['autoload']['psr-0']);

        // Other keys unaffected
        $this->assertArrayHasKey('Horde_Exception', $result['autoload']['psr-0']);
        $this->assertEquals('lib/', $result['autoload']['psr-0']['Horde_Exception']);
    }

    /**
     * Test empty string key with empty string value.
     *
     * Edge case: Both key and value are empty strings.
     */
    public function testEmptyStringKeyEmptyValue(): void
    {
        $yaml = <<<YAML
            "": ""
            YAML;

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey('', $result);
        $this->assertSame('', $result['']);
        $this->assertArrayNotHasKey(0, $result);
    }

    /**
     * Test empty string key with null value.
     *
     * Distinguishes empty string key from null value.
     */
    public function testEmptyStringKeyNullValue(): void
    {
        $yaml = <<<YAML
            "": null
            YAML;

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey('', $result);
        $this->assertNull($result['']);
        $this->assertArrayNotHasKey(0, $result);
    }

    /**
     * Test empty string key with complex nested value.
     */
    public function testEmptyStringKeyComplexValue(): void
    {
        $yaml = <<<YAML
            "":
              nested: value
              list:
                - item1
                - item2
            YAML;

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey('', $result);
        $this->assertIsArray($result['']);
        $this->assertArrayHasKey('nested', $result['']);
        $this->assertEquals('value', $result['']['nested']);
        $this->assertArrayHasKey('list', $result['']);
        $this->assertIsArray($result['']['list']);
        $this->assertEquals(['item1', 'item2'], $result['']['list']);
    }

    // =========================================================================
    // GROUP 2: Edge Cases - Falsy Values as Keys
    // These tests verify the fix handles ALL PHP falsy values correctly.
    // =========================================================================

    /**
     * Test string "0" as key (different from numeric 0).
     *
     * Note: PHP treats "0" and 0 as same array key due to type juggling.
     * This test documents current behavior.
     */
    public function testStringZeroKey(): void
    {
        $yaml = <<<YAML
            "0": string-zero
            YAML;

        $result = Yaml::load($yaml);

        // PHP arrays: "0" and 0 are the same key
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey('0', $result);
        $this->assertEquals('string-zero', $result[0]);
        $this->assertEquals('string-zero', $result['0']);
    }

    /**
     * Test numeric 0 as key.
     *
     * Verifies fix doesn't break legitimate numeric keys.
     */
    public function testNumericZeroKey(): void
    {
        $yaml = <<<YAML
            0: numeric-zero
            YAML;

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey(0, $result);
        $this->assertEquals('numeric-zero', $result[0]);
    }

    /**
     * Test boolean false as key.
     *
     * YAML allows false as key. Should be preserved or converted per spec.
     */
    public function testBooleanFalseKey(): void
    {
        $yaml = <<<YAML
            false: bool-false
            YAML;

        $result = Yaml::load($yaml);

        // YAML spec: false becomes boolean, PHP converts to empty string key
        // This documents existing behavior
        $this->assertTrue(
            isset($result[false]) || isset($result[0]) || isset($result['false']) || isset($result[''])
        );
    }

    /**
     * Test that array notation uses numeric indices (null keys).
     *
     * This is the INTENDED behavior of line 410 check.
     */
    public function testArrayNotationUsesNumericIndex(): void
    {
        $yaml = <<<YAML
            - item1
            - item2
            - item3
            YAML;

        $result = Yaml::load($yaml);

        // Array notation should create numeric indices 0, 1, 2
        $this->assertArrayHasKey(0, $result);
        $this->assertEquals('item1', $result[0]);
        $this->assertArrayHasKey(1, $result);
        $this->assertEquals('item2', $result[1]);
        $this->assertArrayHasKey(2, $result);
        $this->assertEquals('item3', $result[2]);

        // Should NOT have empty string key
        $this->assertArrayNotHasKey('', $result);
    }

    // =========================================================================
    // GROUP 3: Downstream Behavior - Lines 818/840
    // These tests verify lines 818/840 don't reintroduce the bug.
    // =========================================================================

    /**
     * Test empty string key when node has children.
     *
     * Exercises line 818 in _nodeArrayizeData() with children=true.
     */
    public function testEmptyStringKeyWithChildren(): void
    {
        $yaml = <<<YAML
            parent:
              "":
                child1: value1
                child2: value2
              other: other-value
            YAML;

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey('parent', $result);
        $this->assertArrayHasKey('', $result['parent']);
        $this->assertIsArray($result['parent']['']);
        $this->assertArrayHasKey('child1', $result['parent']['']);
        $this->assertEquals('value1', $result['parent']['']['child1']);
        $this->assertArrayNotHasKey(0, $result['parent']);
    }

    /**
     * Test empty string key in leaf node (no children).
     *
     * Exercises line 840 in _nodeArrayizeData() with children=false.
     */
    public function testEmptyStringKeyLeafNode(): void
    {
        $yaml = <<<YAML
            parent:
              "": leaf-value
              other: other-value
            YAML;

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey('parent', $result);
        $this->assertArrayHasKey('', $result['parent']);
        $this->assertEquals('leaf-value', $result['parent']['']);
        $this->assertArrayNotHasKey(0, $result['parent']);
    }

    // =========================================================================
    // GROUP 4: Already-Good Cases (Regression Tests)
    // These tests ensure the fix doesn't break existing functionality.
    // =========================================================================

    /**
     * Regression test: Normal string keys should continue working.
     */
    public function testNormalStringKeysPreserved(): void
    {
        $yaml = <<<YAML
            key1: value1
            key2: value2
            longKeyName: long-value
            YAML;

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey('key1', $result);
        $this->assertEquals('value1', $result['key1']);
        $this->assertArrayHasKey('key2', $result);
        $this->assertEquals('value2', $result['key2']);
        $this->assertArrayHasKey('longKeyName', $result);
        $this->assertEquals('long-value', $result['longKeyName']);
    }

    /**
     * Regression test: Numeric string keys should work.
     */
    public function testNumericStringKeysWork(): void
    {
        $yaml = <<<YAML
            "123": value1
            "456": value2
            YAML;

        $result = Yaml::load($yaml);

        // PHP converts numeric strings to integers as array keys
        $this->assertArrayHasKey(123, $result);
        $this->assertEquals('value1', $result[123]);
        $this->assertArrayHasKey(456, $result);
        $this->assertEquals('value2', $result[456]);
    }

    /**
     * Regression test: Special characters in keys.
     */
    public function testSpecialCharacterKeysWork(): void
    {
        $yaml = <<<YAML
            "key:with:colons": value1
            "key with spaces": value2
            "key-with-dashes": value3
            "key_with_underscores": value4
            YAML;

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey('key:with:colons', $result);
        $this->assertEquals('value1', $result['key:with:colons']);
        $this->assertArrayHasKey('key with spaces', $result);
        $this->assertEquals('value2', $result['key with spaces']);
        $this->assertArrayHasKey('key-with-dashes', $result);
        $this->assertEquals('value3', $result['key-with-dashes']);
        $this->assertArrayHasKey('key_with_underscores', $result);
        $this->assertEquals('value4', $result['key_with_underscores']);
    }

    /**
     * Regression test: Array notation with numeric indices.
     */
    public function testArrayNumericIndicesWork(): void
    {
        $yaml = <<<YAML
            list:
              - first
              - second
              - third
            YAML;

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey('list', $result);
        $this->assertIsArray($result['list']);
        $this->assertCount(3, $result['list']);
        $this->assertEquals('first', $result['list'][0]);
        $this->assertEquals('second', $result['list'][1]);
        $this->assertEquals('third', $result['list'][2]);
    }

    /**
     * Regression test: Mixed numeric and string keys.
     */
    public function testMixedArrayKeysWork(): void
    {
        $yaml = <<<YAML
            mixed:
              0: numeric-zero
              1: numeric-one
              key: string-key
              another: another-string
            YAML;

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey('mixed', $result);
        $this->assertArrayHasKey(0, $result['mixed']);
        $this->assertEquals('numeric-zero', $result['mixed'][0]);
        $this->assertArrayHasKey(1, $result['mixed']);
        $this->assertEquals('numeric-one', $result['mixed'][1]);
        $this->assertArrayHasKey('key', $result['mixed']);
        $this->assertEquals('string-key', $result['mixed']['key']);
        $this->assertArrayHasKey('another', $result['mixed']);
        $this->assertEquals('another-string', $result['mixed']['another']);
    }

    /**
     * Regression test: Deeply nested YAML structures.
     */
    public function testDeeplyNestedStructuresWork(): void
    {
        $yaml = <<<YAML
            level1:
              level2:
                level3:
                  level4:
                    level5: deep-value
            YAML;

        $result = Yaml::load($yaml);

        $this->assertEquals(
            'deep-value',
            $result['level1']['level2']['level3']['level4']['level5']
        );
    }

    // =========================================================================
    // GROUP 5: Real-World Use Cases
    // These tests verify actual use cases from Horde framework.
    // =========================================================================

    /**
     * Real-world test: PSR-0 autoload with empty prefix for global namespace.
     *
     * This is the case that prompted the bug report and fix.
     */
    public function testPsr0EmptyPrefixRealWorld(): void
    {
        $yaml = <<<YAML
            autoload:
              psr-0:
                "": compat/
                Horde_Exception: lib/
            YAML;

        $result = Yaml::load($yaml);

        $psr0 = $result['autoload']['psr-0'];

        // Must have empty string key for global namespace fallback
        $this->assertArrayHasKey('', $psr0);
        $this->assertEquals('compat/', $psr0['']);

        // Must NOT have numeric 0 key
        $this->assertArrayNotHasKey(0, $psr0);

        // Must have normal PSR-0 prefix
        $this->assertArrayHasKey('Horde_Exception', $psr0);
        $this->assertEquals('lib/', $psr0['Horde_Exception']);
    }

    /**
     * Real-world test: Empty prefix in multiple autoload sections.
     */
    public function testMultipleEmptyPrefixesRealWorld(): void
    {
        $yaml = <<<YAML
            autoload:
              psr-0:
                "": compat/
              classmap:
                - legacy/
            autoload-dev:
              psr-0:
                "": test-compat/
            YAML;

        $result = Yaml::load($yaml);

        // Production autoload empty prefix
        $this->assertArrayHasKey('', $result['autoload']['psr-0']);
        $this->assertEquals('compat/', $result['autoload']['psr-0']['']);

        // Dev autoload empty prefix
        $this->assertArrayHasKey('', $result['autoload-dev']['psr-0']);
        $this->assertEquals('test-compat/', $result['autoload-dev']['psr-0']['']);
    }

    /**
     * Real-world test: Complete .horde.yml autoload section.
     */
    public function testHordeYmlAutoloadSectionComplete(): void
    {
        $yaml = <<<YAML
            autoload:
              psr-0:
                Horde_Exception: lib/
                "": compat/
              psr-4:
                Horde\\Exception\\: src/
            YAML;

        $result = Yaml::load($yaml);

        $autoload = $result['autoload'];

        // PSR-0 section
        $this->assertArrayHasKey('psr-0', $autoload);
        $this->assertArrayHasKey('', $autoload['psr-0']);
        $this->assertEquals('compat/', $autoload['psr-0']['']);
        $this->assertArrayHasKey('Horde_Exception', $autoload['psr-0']);
        $this->assertEquals('lib/', $autoload['psr-0']['Horde_Exception']);

        // PSR-4 section
        $this->assertArrayHasKey('psr-4', $autoload);
        $this->assertArrayHasKey('Horde\\Exception\\', $autoload['psr-4']);
        $this->assertEquals('src/', $autoload['psr-4']['Horde\\Exception\\']);
    }

    // =========================================================================
    // GROUP 6: Edge Cases and Boundaries
    // =========================================================================

    /**
     * Edge case: Single space as key (not empty string).
     */
    public function testSingleSpaceKeyNotEmpty(): void
    {
        $yaml = <<<YAML
            " ": space-value
            YAML;

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey(' ', $result);
        $this->assertEquals('space-value', $result[' ']);
        $this->assertArrayNotHasKey('', $result);
        $this->assertArrayNotHasKey(0, $result);
    }

    /**
     * Edge case: Tab character as key.
     */
    public function testTabCharacterKey(): void
    {
        $yaml = "\"\t\": tab-value";

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey("\t", $result);
        $this->assertEquals('tab-value', $result["\t"]);
    }

    /**
     * Edge case: Multiline value with empty string key.
     */
    public function testEmptyKeyMultilineValue(): void
    {
        $yaml = <<<YAML
            "": |
              line1
              line2
              line3
            YAML;

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey('', $result);
        $this->assertStringContainsString('line1', $result['']);
        $this->assertStringContainsString('line2', $result['']);
        $this->assertStringContainsString('line3', $result['']);
    }

    /**
     * Edge case: Empty string key at document root.
     */
    public function testEmptyKeyAtDocumentRoot(): void
    {
        $yaml = <<<YAML
            "": root-value
            normal: other-value
            YAML;

        $result = Yaml::load($yaml);

        $this->assertArrayHasKey('', $result);
        $this->assertEquals('root-value', $result['']);
        $this->assertArrayHasKey('normal', $result);
        $this->assertEquals('other-value', $result['normal']);
        $this->assertCount(2, $result);
    }

    /**
     * Edge case: Empty string with single quotes vs double quotes.
     */
    public function testEmptyStringSingleVsDoubleQuote(): void
    {
        // Double quotes
        $yaml1 = '"": double-quote';
        $result1 = Yaml::load($yaml1);
        $this->assertArrayHasKey('', $result1);
        $this->assertEquals('double-quote', $result1['']);

        // Single quotes
        $yaml2 = "'': single-quote";
        $result2 = Yaml::load($yaml2);
        $this->assertArrayHasKey('', $result2);
        $this->assertEquals('single-quote', $result2['']);
    }
}
