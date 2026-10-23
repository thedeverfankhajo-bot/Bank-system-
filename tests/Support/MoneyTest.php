<?php

declare(strict_types=1);

namespace BankSystem\Tests\Support;

use BankSystem\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase{
    public function testParsesDecimalsWithoutFloatingPointRounding() : void{
        self::assertSame(1234, Money::parse('12.34'));
        self::assertSame(1200, Money::parse('12'));
        self::assertSame(120, Money::parse('1.2'));
        self::assertSame(5, Money::parse('0.05'));
    }

    public function testRejectsMalformedOrUnsafeValues() : void{
        foreach(['', '0', '00.10', '-1.00', '+1.00', '1.', '.50', '1.234', '1e3', '1,000.00'] as $input){
            self::assertNull(Money::parse($input), 'Expected invalid money input: ' . $input);
        }
        self::assertNull(Money::parse('999999999999999999999999999999.99'));
    }

    public function testZeroIsAllowedOnlyWhenRequested() : void{
        self::assertNull(Money::parse('0'));
        self::assertSame(0, Money::parse('0', true));
        self::assertSame(0, Money::parse('0.00', true));
    }

    public function testFormatsIntegerMinorUnits() : void{
        self::assertSame('$1,234.50', Money::format(123450));
        self::assertSame('€0.09', Money::format(9, '€'));
        self::assertSame('-$1.25', Money::format(-125));
    }
}
