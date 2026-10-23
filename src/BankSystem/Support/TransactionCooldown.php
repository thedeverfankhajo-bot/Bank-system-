<?php

declare(strict_types=1);

namespace BankSystem\Support;

/**
 * Tracks successful player transactions for the command-layer cooldown.
 *
 * The server calls this on its main thread. State is keyed by stable player UUID,
 * never by display name, and callers should forget a UUID when the player quits.
 */
final class TransactionCooldown{
    /** @var array<string, float> */
    private array $lastSuccessAt = [];

    public function isActive(string $playerUuid, float $now, int $cooldownSeconds) : bool{
        if($playerUuid === '' || $cooldownSeconds <= 0){
            return false;
        }

        $last = $this->lastSuccessAt[$playerUuid] ?? null;
        if($last === null){
            return false;
        }

        // A wall-clock adjustment must never strand a player behind a cooldown.
        if($now < $last || $now - $last >= $cooldownSeconds){
            unset($this->lastSuccessAt[$playerUuid]);
            return false;
        }

        return true;
    }

    public function recordSuccess(string $playerUuid, float $now) : void{
        if($playerUuid !== ''){
            $this->lastSuccessAt[$playerUuid] = $now;
        }
    }

    public function forget(string $playerUuid) : void{
        unset($this->lastSuccessAt[$playerUuid]);
    }
}
