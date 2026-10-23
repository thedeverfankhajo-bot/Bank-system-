<?php

declare(strict_types=1);

namespace BankSystem\Tests\Support;

use BankSystem\Support\PlayerIdentity;
use PHPUnit\Framework\TestCase;

final class PlayerIdentityTest extends TestCase{
    public function testOnlyTheOriginalNonEmptyUuidMatches() : void{
        self::assertTrue(PlayerIdentity::matches('uuid-original', 'uuid-original'));
        self::assertFalse(PlayerIdentity::matches('uuid-original', 'uuid-replacement'));
        self::assertFalse(PlayerIdentity::matches('uuid-original', null));
        self::assertFalse(PlayerIdentity::matches('uuid-original', ''));
        self::assertFalse(PlayerIdentity::matches('', 'uuid-original'));
        self::assertFalse(PlayerIdentity::matches('', null));
    }
}
