<?php

declare(strict_types=1);

namespace BankSystem\Tests\Support;

use BankSystem\Support\BedrockText;
use PHPUnit\Framework\TestCase;

final class BedrockTextTest extends TestCase{
    public function testStripsBedrockFormattingCodesWithoutLeavingCodeLetters() : void{
        self::assertSame('BlakeINJECTED', BedrockText::sanitize("Blake§cINJECTED"));
        self::assertSame('AlexGoldBold', BedrockText::sanitize('Alex§gGold§lBold'));
        self::assertSame('Casey', BedrockText::sanitize('Casey§r'));
    }

    public function testRemovesControlBytesBeforeFormattingCodes() : void{
        self::assertSame('BlakeINJECTED', BedrockText::sanitize("Blake§\ncINJECTED"));
        self::assertSame('AliceBob', BedrockText::sanitize("Alice\r\nBob"));
        self::assertSame('SafeText', BedrockText::sanitize("Safe\0Text"));
    }

    public function testKeepsOrdinaryDisplayTextIntact() : void{
        self::assertSame('Alex_123', BedrockText::sanitize('Alex_123'));
        self::assertSame('玩家123', BedrockText::sanitize('玩家123'));
    }
}
