<?php

declare(strict_types=1);

namespace BankSystem\Tests\Support;

use BankSystem\Support\TransactionCooldown;
use PHPUnit\Framework\TestCase;

final class TransactionCooldownTest extends TestCase{
    public function testCooldownBeginsOnlyAfterSuccessAndExpiresAtItsBoundary() : void{
        $cooldown = new TransactionCooldown();

        self::assertFalse($cooldown->isActive('player-a', 100.0, 1));
        $cooldown->recordSuccess('player-a', 100.0);

        self::assertTrue($cooldown->isActive('player-a', 100.5, 1));
        self::assertFalse($cooldown->isActive('player-a', 101.0, 1));
        self::assertFalse($cooldown->isActive('player-a', 101.1, 1));
    }

    public function testCooldownsAreIsolatedByUuidAndCanBeForgotten() : void{
        $cooldown = new TransactionCooldown();
        $cooldown->recordSuccess('player-a', 50.0);

        self::assertTrue($cooldown->isActive('player-a', 50.25, 1));
        self::assertFalse($cooldown->isActive('player-b', 50.25, 1));

        $cooldown->forget('player-a');
        self::assertFalse($cooldown->isActive('player-a', 50.25, 1));
    }

    public function testDisabledCooldownAndBackwardClockMovementDoNotBlockTransactions() : void{
        $cooldown = new TransactionCooldown();
        $cooldown->recordSuccess('player-a', 100.0);

        self::assertFalse($cooldown->isActive('player-a', 100.1, 0));
        self::assertFalse($cooldown->isActive('player-a', 99.0, 5));
        self::assertFalse($cooldown->isActive('', 100.1, 5));
    }
}
