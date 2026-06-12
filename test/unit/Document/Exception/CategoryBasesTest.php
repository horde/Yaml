<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Exception;

use Horde\Exception\HordeThrowable;
use Horde\Yaml\Document\EmitException;
use Horde\Yaml\Document\Exception;
use Horde\Yaml\Document\IoException;
use Horde\Yaml\Document\ParseException;
use Horde\Yaml\Document\StructuralException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ReflectionClass;

/**
 * Verifies the document layer's exception hierarchy: every category base
 * implements the umbrella interface, implements HordeThrowable, and is
 * catchable as the appropriate SPL ancestor.
 */
#[CoversClass(ParseException::class)]
#[CoversClass(EmitException::class)]
#[CoversClass(IoException::class)]
#[CoversClass(StructuralException::class)]
final class CategoryBasesTest extends TestCase
{
    public function testParseExceptionIsCatchableViaUmbrella(): void
    {
        $caught = null;
        try {
            throw new ParseException('parse went wrong');
        } catch (Exception $e) {
            $caught = $e;
        }
        $this->assertInstanceOf(ParseException::class, $caught);
        $this->assertSame('parse went wrong', $caught->getMessage());
    }

    public function testParseExceptionIsCatchableViaHordeThrowable(): void
    {
        $caught = null;
        try {
            throw new ParseException('boom');
        } catch (HordeThrowable $e) {
            $caught = $e;
        }
        $this->assertInstanceOf(ParseException::class, $caught);
    }

    public function testParseExceptionIsCatchableAsRuntimeException(): void
    {
        $caught = null;
        try {
            throw new ParseException('boom');
        } catch (RuntimeException $e) {
            $caught = $e;
        }
        $this->assertInstanceOf(ParseException::class, $caught);
    }

    public function testEmitExceptionIsCatchableViaUmbrella(): void
    {
        $caught = null;
        try {
            throw new EmitException('emit went wrong');
        } catch (Exception $e) {
            $caught = $e;
        }
        $this->assertInstanceOf(EmitException::class, $caught);
    }

    public function testEmitExceptionIsCatchableAsRuntimeException(): void
    {
        $caught = null;
        try {
            throw new EmitException('boom');
        } catch (RuntimeException $e) {
            $caught = $e;
        }
        $this->assertInstanceOf(EmitException::class, $caught);
    }

    public function testIoExceptionIsCatchableViaUmbrella(): void
    {
        $caught = null;
        try {
            throw new IoException('io went wrong');
        } catch (Exception $e) {
            $caught = $e;
        }
        $this->assertInstanceOf(IoException::class, $caught);
    }

    public function testIoExceptionIsCatchableAsRuntimeException(): void
    {
        $caught = null;
        try {
            throw new IoException('boom');
        } catch (RuntimeException $e) {
            $caught = $e;
        }
        $this->assertInstanceOf(IoException::class, $caught);
    }

    public function testStructuralExceptionIsCatchableViaUmbrella(): void
    {
        $caught = null;
        try {
            throw new StructuralException('structural went wrong');
        } catch (Exception $e) {
            $caught = $e;
        }
        $this->assertInstanceOf(StructuralException::class, $caught);
    }

    public function testStructuralExceptionIsCatchableAsLogicException(): void
    {
        $caught = null;
        try {
            throw new StructuralException('boom');
        } catch (LogicException $e) {
            $caught = $e;
        }
        $this->assertInstanceOf(StructuralException::class, $caught);
    }

    public function testStructuralExceptionIsNotCatchableAsRuntimeException(): void
    {
        $caught = null;
        try {
            throw new StructuralException('boom');
        } catch (RuntimeException) {
            $this->fail('StructuralException should not be catchable as RuntimeException');
        } catch (LogicException $e) {
            $caught = $e;
        }
        $this->assertInstanceOf(StructuralException::class, $caught);
    }

    public function testCategoryBasesHaveDetailsTrait(): void
    {
        $e = new ParseException('msg');
        $e->setDetails('extra context');
        $this->assertSame('extra context', $e->getDetails());
    }

    public function testUmbrellaInterfaceExtendsHordeThrowable(): void
    {
        $reflection = new ReflectionClass(Exception::class);
        $this->assertTrue($reflection->isInterface());
        $this->assertTrue($reflection->implementsInterface(HordeThrowable::class));
    }
}
