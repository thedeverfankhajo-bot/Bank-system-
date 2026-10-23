<?php

declare(strict_types=1);

namespace BankSystem\Support;

final class PlayerIdentity{
    private function __construct(){
    }

    /**
     * Confirms that a player resolved after a UI round-trip is the same account
     * that was originally presented to the user, even if the name was reused.
     */
    public static function matches(string $expectedUuid, ?string $currentUuid) : bool{
        return $expectedUuid !== '' && $currentUuid !== null && $currentUuid !== '' && $expectedUuid === $currentUuid;
    }
}
